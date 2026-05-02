(() => {
  const app = document.querySelector("[data-reader-app]");
  if (!(app instanceof HTMLElement)) {
    return;
  }

  const manifestUrl = (app.getAttribute("data-reader-manifest-url") || "").trim();
  const pageUrl = (app.getAttribute("data-reader-page-url") || "").trim();
  const initialFormat = (app.getAttribute("data-reader-format") || "").trim().toLowerCase();
  const pdfWorkerUrl = (app.getAttribute("data-reader-pdf-worker-url") || "").trim();
  const bookId = Number.parseInt(app.getAttribute("data-reader-book-id") || "", 10);
  const layout = app.querySelector("[data-reader-layout]");
  const viewport = app.querySelector(".reader-viewport");
  const contentNode = app.querySelector("[data-reader-content]");
  const statusNode = app.querySelector("[data-reader-status]");
  const tocPanel = app.querySelector("[data-reader-toc-panel]");
  const tocList = app.querySelector("[data-reader-toc-list]");
  const emptyNode = app.querySelector("[data-reader-empty]");
  const prevButton = app.querySelector("[data-reader-prev]");
  const nextButton = app.querySelector("[data-reader-next]");
  const openTocButton = app.querySelector("[data-reader-open-toc]");
  const closeTocButton = app.querySelector("[data-reader-close-toc]");

  if (!(viewport instanceof HTMLElement) || !(contentNode instanceof HTMLElement) || !manifestUrl || !pageUrl || !Number.isInteger(bookId) || bookId <= 0) {
    return;
  }

  document.body.classList.add("reader-page-body");
  document.documentElement.classList.add("reader-page-html");

  const state = {
    manifest: null,
    currentSection: null,
    currentFragment: "",
    currentTheme: document.body.getAttribute("data-theme") === "dark" ? "dark" : "light",
    pdfDocument: null,
    pdfPage: 1,
    pdfModule: null,
    mode: "epub",
  };

  const loadingManifestText = (app.getAttribute("data-reader-loading-manifest") || "載入閱讀目錄中...").trim();
  const loadingSectionText = (app.getAttribute("data-reader-loading-section") || "載入章節中...").trim();
  const manifestErrorText = (app.getAttribute("data-reader-manifest-error") || "無法載入閱讀目錄。").trim();
  const openTocText = (app.getAttribute("data-reader-open-toc-label") || "目錄").trim();
  const closeTocText = (app.getAttribute("data-reader-close-toc-label") || "關閉").trim();
  let statusTimer = null;

  const normalizeTheme = () => (document.body.getAttribute("data-theme") === "dark" ? "dark" : "light");

  const setStatus = (message, isError = false) => {
    if (statusNode instanceof HTMLElement) {
      statusNode.textContent = message || "";
      statusNode.hidden = !message;
      statusNode.classList.toggle("is-visible", Boolean(message));
      statusNode.classList.toggle("is-error", Boolean(message) && isError);
      if (statusTimer) {
        window.clearTimeout(statusTimer);
        statusTimer = null;
      }

      if (message) {
        statusTimer = window.setTimeout(() => {
          statusNode.classList.remove("is-visible", "is-error");
          statusNode.hidden = true;
          statusNode.textContent = "";
          statusTimer = null;
        }, 2000);
      }
    }
  };

  const setEmpty = (message) => {
    if (!(emptyNode instanceof HTMLElement)) {
      return;
    }

    if (!message) {
      emptyNode.hidden = true;
      emptyNode.textContent = "";
      return;
    }

    emptyNode.hidden = false;
    emptyNode.textContent = message;
  };

  const closeToc = () => {
    if (tocPanel instanceof HTMLElement) {
      tocPanel.hidden = true;
    }
    if (layout instanceof HTMLElement) {
      layout.classList.remove("reader-layout--toc-open");
    }
    if (openTocButton instanceof HTMLButtonElement) {
      openTocButton.title = openTocText;
      openTocButton.setAttribute("aria-label", openTocText);
      const buttonText = openTocButton.querySelector("[data-reader-open-toc-text]");
      if (buttonText instanceof HTMLElement) {
        buttonText.textContent = openTocText;
      }
    }
  };

  const openToc = () => {
    if (tocPanel instanceof HTMLElement) {
      tocPanel.hidden = false;
    }
    if (layout instanceof HTMLElement) {
      layout.classList.add("reader-layout--toc-open");
    }
    if (openTocButton instanceof HTMLButtonElement) {
      openTocButton.title = closeTocText;
      openTocButton.setAttribute("aria-label", closeTocText);
      const buttonText = openTocButton.querySelector("[data-reader-open-toc-text]");
      if (buttonText instanceof HTMLElement) {
        buttonText.textContent = closeTocText;
      }
    }
  };

  const normalizeFragment = (fragment) => (typeof fragment === "string" ? fragment.trim().replace(/^#/, "") : "");

  const buildSectionUrl = (sectionId, fragment = "") => {
    const url = new URL(pageUrl, window.location.href);
    url.searchParams.set("id", String(bookId));
    url.searchParams.set("section", sectionId);
    url.searchParams.set("theme", state.currentTheme);
    const normalizedFragment = normalizeFragment(fragment);
    url.hash = normalizedFragment ? encodeURIComponent(normalizedFragment) : "";
    return url.toString();
  };

  const scrollToFragment = (fragment = "") => {
    const normalizedFragment = normalizeFragment(fragment);
    if (!normalizedFragment) {
      viewport.scrollTop = 0;
      return;
    }

    const target = contentNode.querySelector(`#${CSS.escape(normalizedFragment)}, a[name="${CSS.escape(normalizedFragment)}"]`);
    if (target instanceof HTMLElement) {
      target.scrollIntoView({ block: "start" });
      return;
    }

    viewport.scrollTop = 0;
  };

  const renderSectionMarkup = (markup) => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(markup, "text/html");
    const body = doc.body;
    const classes = ["reader-viewport__content"];
    const bodyClass = (body.getAttribute("class") || "").trim();
    if (bodyClass) {
      classes.push(bodyClass);
    }

    contentNode.className = classes.join(" ");
    contentNode.innerHTML = body.innerHTML;
    const sectionValue = body.getAttribute("data-reader-section") || "";
    if (sectionValue) {
      state.currentSection = sectionValue;
    }

    window.requestAnimationFrame(() => {
      scrollToFragment(state.currentFragment);
    });
  };

  const getSections = () => Array.isArray(state.manifest?.sections) ? state.manifest.sections : [];

  const setPdfMode = (enabled) => {
    if (prevButton instanceof HTMLButtonElement) {
      prevButton.disabled = enabled;
    }

    if (nextButton instanceof HTMLButtonElement) {
      nextButton.disabled = enabled;
    }
  };

  const updatePdfButtons = () => {
    if (!(prevButton instanceof HTMLButtonElement) || !(nextButton instanceof HTMLButtonElement) || !state.pdfDocument) {
      return;
    }

    prevButton.disabled = state.pdfPage <= 1;
    nextButton.disabled = state.pdfPage >= state.pdfDocument.numPages;
  };

  const ensurePdfModule = async () => {
    if (state.pdfModule) {
      return state.pdfModule;
    }

    if (!pdfWorkerUrl) {
      throw new Error(manifestErrorText);
    }

    const workerUrl = new URL(pdfWorkerUrl, window.location.href).toString();
    const pdfjs = window.pdfjsLib;
    if (!pdfjs || typeof pdfjs.getDocument !== "function") {
      throw new Error(manifestErrorText);
    }
    pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
    state.pdfModule = pdfjs;
    return pdfjs;
  };

  const renderPdfPage = async (pageNumber) => {
    if (!state.pdfDocument) {
      return;
    }

    const boundedPage = Math.min(Math.max(pageNumber, 1), state.pdfDocument.numPages);
    state.pdfPage = boundedPage;
    updatePdfButtons();
    setStatus(`${loadingSectionText} (${boundedPage}/${state.pdfDocument.numPages})`);

    const page = await state.pdfDocument.getPage(boundedPage);
    const viewportWidth = Math.max(contentNode.clientWidth - 48, 320);
    const baseViewport = page.getViewport({ scale: 1 });
    const scale = viewportWidth / baseViewport.width;
    const viewportConfig = page.getViewport({ scale });

    const host = contentNode.querySelector(".reader-pdf-stage");
    const canvas = contentNode.querySelector(".reader-pdf-canvas");
    if (!(host instanceof HTMLElement) || !(canvas instanceof HTMLCanvasElement)) {
      return;
    }

    canvas.width = Math.ceil(viewportConfig.width);
    canvas.height = Math.ceil(viewportConfig.height);
    canvas.style.width = `${viewportConfig.width}px`;
    canvas.style.height = `${viewportConfig.height}px`;

    const context = canvas.getContext("2d", { alpha: false });
    if (!context) {
      return;
    }

    await page.render({
      canvasContext: context,
      viewport: viewportConfig,
    }).promise;

    viewport.scrollTop = 0;
  };

  const renderPdfDocument = async (documentUrl) => {
    state.mode = "pdf";
    contentNode.className = "reader-viewport__content reader-pdf-host";
    contentNode.innerHTML = '<div class="reader-pdf-stage"><canvas class="reader-pdf-canvas"></canvas></div><div class="reader-pdf-meta"></div>';
    const pdfjs = await ensurePdfModule();
    const sourceUrl = new URL(documentUrl, window.location.href).toString();
    const loadingTask = pdfjs.getDocument({
      url: sourceUrl,
      withCredentials: true,
      disableFontFace: true,
      useSystemFonts: false,
    });
    state.pdfDocument = await loadingTask.promise;
    state.pdfPage = 1;
    await renderPdfPage(1);
  };

  const renderComicPage = (section) => {
    if (!section || !section.image_url) {
      throw new Error(loadingSectionText);
    }

    state.mode = "comic";
    const host = document.createElement("div");
    host.className = "reader-comic-host";
    const image = document.createElement("img");
    image.className = "reader-comic-image";
    image.alt = section.label || app.getAttribute("data-reader-title") || "";
    image.loading = "eager";
    image.src = new URL(section.image_url, window.location.href).toString();
    host.appendChild(image);
    contentNode.className = "reader-viewport__content reader-comic-stage";
    contentNode.replaceChildren(host);
    viewport.scrollTop = 0;
  };

  const syncButtons = () => {
    const sections = getSections();
    const currentIndex = sections.findIndex((section) => section.id === state.currentSection);

    if (prevButton instanceof HTMLButtonElement) {
      prevButton.disabled = currentIndex <= 0;
    }

    if (nextButton instanceof HTMLButtonElement) {
      nextButton.disabled = currentIndex < 0 || currentIndex >= sections.length - 1;
    }

    if (tocList instanceof HTMLElement) {
      tocList.querySelectorAll("[data-reader-section]").forEach((link) => {
        const active = link.getAttribute("data-reader-section") === state.currentSection;
        const fragmentMatch = normalizeFragment(link.getAttribute("data-reader-fragment") || "") === normalizeFragment(state.currentFragment);
        link.classList.toggle("is-active", active && fragmentMatch);
      });
    }
  };

  const loadSection = async (sectionId, fragment = "") => {
    if (!sectionId) {
      return;
    }

    state.currentSection = sectionId;
    state.currentFragment = normalizeFragment(fragment);
    state.currentTheme = normalizeTheme();
    setStatus(loadingSectionText);
    setEmpty("");
    syncButtons();

    const sections = getSections();
    const targetSection = sections.find((section) => section.id === sectionId) || null;
    if (state.manifest?.reading_mode === "comic-pages" || (state.manifest?.format || initialFormat) === "cbz") {
      try {
        renderComicPage(targetSection);
      } catch (error) {
        const message = error instanceof Error ? error.message : loadingSectionText;
        setStatus(message, true);
        setEmpty(message);
      }
      return;
    }

    try {
      const response = await fetch(buildSectionUrl(sectionId, state.currentFragment), {
        headers: { Accept: "text/html" },
        cache: "no-store",
      });

      if (!response.ok) {
        throw new Error(response.statusText || loadingSectionText);
      }

      renderSectionMarkup(await response.text());
    } catch (error) {
      const message = error instanceof Error ? error.message : loadingSectionText;
      setStatus(message, true);
      setEmpty(message);
    }
  };

  const renderToc = () => {
    if (!(tocList instanceof HTMLElement)) {
      return;
    }

    tocList.replaceChildren();

    const items = Array.isArray(state.manifest?.toc) ? state.manifest.toc : [];
    items.forEach((item) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "reader-toc__item";
      button.setAttribute("data-reader-section", item.section);
      button.setAttribute("data-reader-fragment", normalizeFragment(item.fragment || ""));
      button.textContent = item.label || item.section;
      button.addEventListener("click", () => {
        loadSection(item.section, item.fragment || "");
        closeToc();
      });
      tocList.appendChild(button);
    });
  };

  const loadManifest = async () => {
    try {
      setStatus(loadingManifestText);
      const response = await fetch(manifestUrl, {
        headers: { Accept: "application/json" },
      });

      if (!response.ok) {
        throw new Error(manifestErrorText);
      }

      const manifest = await response.json();
      if (!manifest || manifest.error) {
        throw new Error(manifest?.error || manifestErrorText);
      }

      state.manifest = manifest;
      if ((manifest.format || initialFormat) === "pdf" || manifest.reading_mode === "pdf-document") {
        state.mode = "pdf";
        setPdfMode(true);
        const documentUrl = typeof manifest.document_url === "string" ? manifest.document_url.trim() : "";
        if (!documentUrl) {
          throw new Error(manifestErrorText);
        }
        setEmpty("");
        await renderPdfDocument(documentUrl);
        return;
      }

      state.mode = manifest.reading_mode === "comic-pages" || (manifest.format || initialFormat) === "cbz" ? "comic" : "epub";
      setPdfMode(false);
      renderToc();
      loadSection(manifest.initial_section || manifest.sections?.[0]?.id || null, manifest.initial_fragment || "");
    } catch (error) {
      const message = error instanceof Error ? error.message : manifestErrorText;
      setStatus(message, true);
      setEmpty(message);
    }
  };

  if (openTocButton instanceof HTMLButtonElement) {
    openTocButton.addEventListener("click", () => {
      if (tocPanel instanceof HTMLElement && !tocPanel.hidden) {
        closeToc();
        return;
      }
      openToc();
    });
  }

    if (prevButton instanceof HTMLButtonElement) {
      prevButton.addEventListener("click", () => {
        if (state.pdfDocument) {
          renderPdfPage(state.pdfPage - 1);
          return;
        }
        const sections = getSections();
        const currentIndex = sections.findIndex((section) => section.id === state.currentSection);
        if (currentIndex > 0) {
          loadSection(sections[currentIndex - 1].id, "");
        }
      });
    }

    if (nextButton instanceof HTMLButtonElement) {
      nextButton.addEventListener("click", () => {
        if (state.pdfDocument) {
          renderPdfPage(state.pdfPage + 1);
          return;
        }
        const sections = getSections();
        const currentIndex = sections.findIndex((section) => section.id === state.currentSection);
        if (currentIndex >= 0 && currentIndex < sections.length - 1) {
          loadSection(sections[currentIndex + 1].id, "");
        }
      });
    }

  contentNode.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target.closest("a") : null;
    if (!(target instanceof HTMLAnchorElement)) {
      return;
    }

    const href = (target.getAttribute("href") || "").trim();
    if (href === "") {
      return;
    }

    if (href.startsWith("#")) {
      event.preventDefault();
      state.currentFragment = normalizeFragment(href);
      scrollToFragment(state.currentFragment);
      return;
    }

    try {
      const url = new URL(href, window.location.href);
      if (!/\/?reader_page\.php$/i.test(url.pathname)) {
        return;
      }

      event.preventDefault();
      const section = (url.searchParams.get("section") || "").trim();
      loadSection(section, url.hash);
    } catch (_error) {
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && tocPanel instanceof HTMLElement && !tocPanel.hidden) {
      event.preventDefault();
      closeToc();
      return;
    }

    if (event.key === "ArrowLeft" && !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
      if (document.activeElement instanceof HTMLElement && ["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement.tagName)) {
        return;
      }

      if (prevButton instanceof HTMLButtonElement && !prevButton.disabled) {
        event.preventDefault();
        prevButton.click();
      }
    }

    if (event.key === "ArrowRight" && !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
      if (document.activeElement instanceof HTMLElement && ["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement.tagName)) {
        return;
      }

      if (nextButton instanceof HTMLButtonElement && !nextButton.disabled) {
        event.preventDefault();
        nextButton.click();
      }
    }
  });

  const themeObserver = new MutationObserver(() => {
    const nextTheme = normalizeTheme();
    if (nextTheme === state.currentTheme) {
      return;
    }

    state.currentTheme = nextTheme;
    if (state.pdfDocument) {
      renderPdfPage(state.pdfPage);
      return;
    }

    if (state.mode === "comic") {
      const sections = getSections();
      const current = sections.find((section) => section.id === state.currentSection) || null;
      if (current) {
        renderComicPage(current);
      }
      return;
    }

    if (!state.currentSection) {
      return;
    }
    loadSection(state.currentSection, state.currentFragment);
  });

  themeObserver.observe(document.body, {
    attributes: true,
    attributeFilter: ["data-theme"],
  });

  window.addEventListener("pagehide", () => {
    contentNode.replaceChildren();
    state.pdfDocument = null;
    document.documentElement.classList.remove("reader-page-html");
  });

  loadManifest();
})();
