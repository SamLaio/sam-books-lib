(() => {
  const themeToggleButton = document.querySelector("[data-theme-toggle]");
  const themeToggleIcon = document.querySelector("[data-theme-toggle-icon]");
  const scrollTopButton = document.querySelector("[data-scroll-top]");
  const scrollBottomButton = document.querySelector("[data-scroll-bottom]");
  const searchInput = document.querySelector('input[type="search"][name="q"]');
  const searchForm = document.querySelector(".search-form");
  const scanRequestForm = document.querySelector("[data-scan-request-form]");
  const scanRequestButton = scanRequestForm?.querySelector("[data-scan-request-button]") || null;

  const isTypingTarget = (target) => {
    if (!(target instanceof HTMLElement)) {
      return false;
    }

    const tagName = target.tagName.toLowerCase();
    if (tagName === "input" || tagName === "textarea" || tagName === "select") {
      return true;
    }

    if (target.isContentEditable) {
      return true;
    }

    return Boolean(target.closest('[contenteditable="true"]'));
  };

  const isElementVisible = (element) => {
    if (!(element instanceof HTMLElement)) {
      return false;
    }

    if (element.hidden) {
      return false;
    }

    return element.offsetParent !== null || element.getClientRects().length > 0;
  };

  const dialogCloseSelectors = [
    "[data-book-dialog-close]",
    ".cover-lightbox__close",
    "[data-user-dialog-close]",
    "[data-library-dialog-close]",
    "[data-job-dialog-close]",
  ];

  const findTopmostCloseButton = () => {
    const openDialogs = Array.from(document.querySelectorAll("dialog[open]"));
    for (let i = openDialogs.length - 1; i >= 0; i -= 1) {
      const openDialog = openDialogs[i];
      const closeButton = openDialog.querySelector(dialogCloseSelectors.join(", "));
      if (closeButton instanceof HTMLElement && isElementVisible(closeButton)) {
        return closeButton;
      }
    }

    const visibleCloseButtons = Array.from(
      document.querySelectorAll(dialogCloseSelectors.join(", "))
    ).filter((button) => button instanceof HTMLElement && isElementVisible(button));

    return visibleCloseButtons.length > 0 ? visibleCloseButtons[visibleCloseButtons.length - 1] : null;
  };

  const findPagerNavigationUrl = (type) => {
    const navType = type === "previous" ? "previous" : "next";
    const pagerGroups = [
      Array.from(document.querySelectorAll('.admin-tabs__panel:not([hidden]) .pager')),
      Array.from(document.querySelectorAll(".panel .pager")),
      Array.from(document.querySelectorAll(".pager")),
    ];

    for (const pagers of pagerGroups) {
      for (const pager of pagers) {
        if (!isElementVisible(pager)) {
          continue;
        }

        const links = Array.from(pager.querySelectorAll(`a.page-link[data-pager-nav="${navType}"][href], a.page-link[href]`));
        const matchedLink = links.find((link) => {
          const attr = (link.getAttribute("data-pager-nav") || "").trim();
          if (attr !== "") {
            return attr === navType;
          }

          return link.textContent.trim() === (navType === "previous" ? "上一頁" : "下一頁");
        });
        if (matchedLink) {
          return matchedLink.getAttribute("href");
        }
      }
    }

    return null;
  };

  const normalizeTheme = (theme) => (theme === "dark" ? "dark" : "light");

  const persistThemeCookie = (theme) => {
    if (!themeToggleButton) {
      return;
    }

    if (themeToggleButton.getAttribute("data-theme-cookie-enabled") !== "1") {
      return;
    }

    const cookieName = (themeToggleButton.getAttribute("data-theme-cookie-name") || "").trim();
    if (cookieName === "") {
      return;
    }

    const secure = window.location.protocol === "https:" ? "; secure" : "";
    document.cookie = `${encodeURIComponent(cookieName)}=${encodeURIComponent(theme)}; path=/; max-age=31536000; samesite=lax${secure}`;
  };

  const applyTheme = (theme) => {
    const normalizedTheme = normalizeTheme(theme);
    document.body.setAttribute("data-theme", normalizedTheme);
    persistThemeCookie(normalizedTheme);

    if (!themeToggleButton) {
      return normalizedTheme;
    }

    const nextHint = normalizedTheme === "dark" ? "切換白天" : "切換夜間";
    themeToggleButton.setAttribute("aria-label", nextHint);
    themeToggleButton.setAttribute("title", nextHint);
    themeToggleButton.setAttribute("data-theme-current", normalizedTheme);
    if (themeToggleIcon) {
      themeToggleIcon.textContent = normalizedTheme === "dark" ? "☀" : "☾";
    }
    return normalizedTheme;
  };

  const persistTheme = async (theme) => {
    if (!themeToggleButton) {
      return;
    }

    if (themeToggleButton.getAttribute("data-theme-persist") !== "1") {
      return;
    }

    const endpoint = themeToggleButton.getAttribute("data-theme-update-url");
    if (!endpoint) {
      return;
    }

    const body = new URLSearchParams();
    body.set("theme", theme);

    const response = await fetch(endpoint, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
      },
      body: body.toString(),
    });

    if (!response.ok) {
      throw new Error("主題設定儲存失敗。");
    }

    const result = await response.json();
    if (!result || result.ok !== true) {
      throw new Error(result?.error || "主題設定儲存失敗。");
    }
  };

  const setTopNotice = (message, isError = false) => {
    const panel = document.querySelector(".panel");
    if (!panel) {
      return;
    }

    let node = panel.querySelector(".message[data-scan-message], .error[data-scan-message]");
    if (!node) {
      node = document.createElement("div");
      node.setAttribute("data-scan-message", "1");
      const toolbar = panel.querySelector(".toolbar");
      if (toolbar && toolbar.parentNode === panel) {
        toolbar.insertAdjacentElement("afterend", node);
      } else {
        panel.appendChild(node);
      }
    }

    node.className = isError ? "error" : "message";
    node.setAttribute("data-scan-message", "1");
    node.textContent = message;
  };

  if (themeToggleButton) {
    applyTheme(document.body.getAttribute("data-theme") || "light");

    themeToggleButton.addEventListener("click", async () => {
      const currentTheme = normalizeTheme(themeToggleButton.getAttribute("data-theme-current") || document.body.getAttribute("data-theme") || "light");
      const nextTheme = currentTheme === "dark" ? "light" : "dark";
      applyTheme(nextTheme);

      try {
        await persistTheme(nextTheme);
      } catch (error) {
        applyTheme(currentTheme);
        window.alert(error instanceof Error ? error.message : "主題設定儲存失敗。");
      }
    });
  }

  if (scanRequestForm && scanRequestButton) {
    scanRequestForm.addEventListener("submit", async (event) => {
      event.preventDefault();

      const endpoint = scanRequestForm.getAttribute("data-scan-request-endpoint") || scanRequestForm.getAttribute("action") || "scan_request.php";
      const body = new URLSearchParams();
      body.set("action", "rebuild");

      const idleLabel = scanRequestButton.getAttribute("data-label-idle") || "手動重建索引";
      const busyLabel = scanRequestButton.getAttribute("data-label-busy") || "索引重建中";
      scanRequestButton.disabled = true;
      scanRequestButton.textContent = busyLabel;

      try {
        const response = await fetch(endpoint, {
          method: "POST",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          },
          body: body.toString(),
        });

        let payload = null;
        try {
          payload = await response.json();
        } catch (_e) {
          payload = null;
        }

        if (response.status !== 202) {
          throw new Error(payload?.error || "重建請求失敗。");
        }

        setTopNotice(payload?.message || "已收到重建請求，背景排程中。");
      } catch (error) {
        scanRequestButton.disabled = false;
        scanRequestButton.textContent = idleLabel;
        setTopNotice(error instanceof Error ? error.message : "重建請求失敗。", true);
      }
    });
  }

  if (scrollTopButton) {
    scrollTopButton.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  if (scrollBottomButton) {
    scrollBottomButton.addEventListener("click", () => {
      const targetTop = Math.max(
        document.documentElement.scrollHeight,
        document.body.scrollHeight
      );
      window.scrollTo({ top: targetTop, behavior: "smooth" });
    });
  }

  const dialog = document.querySelector("[data-book-dialog]");
  if (!dialog) {
    return;
  }

  const titleNode = dialog.querySelector("[data-book-detail-title]");
  const authorNode = dialog.querySelector("[data-book-detail-author]");
  const tagNode = dialog.querySelector("[data-book-detail-tag]");
  const seriesNode = dialog.querySelector("[data-book-detail-series]");
  const isbnNode = dialog.querySelector("[data-book-detail-isbn]");
  const publisherNode = dialog.querySelector("[data-book-detail-publisher]");
  const languageNode = dialog.querySelector("[data-book-detail-language]");
  const descriptionNode = dialog.querySelector("[data-book-detail-description]");
  const statusNode = dialog.querySelector("[data-book-detail-status]");
  const coverNode = dialog.querySelector("[data-book-detail-cover]");
  const coverImageNode = dialog.querySelector("[data-book-detail-cover-image]");
  const readLink = dialog.querySelector("[data-book-detail-read]");
  const downloadLink = dialog.querySelector("[data-book-detail-download]");
  const sendLink = dialog.querySelector("[data-book-detail-send]");
  const closeButton = dialog.querySelector("[data-book-dialog-close]");
  const canSendBookByEmail = dialog.getAttribute("data-can-send-book") === "1";
  const metadataNodes = {
    author: authorNode,
    tag: tagNode,
    series: seriesNode,
    isbn: isbnNode,
    publisher: publisherNode,
    language: languageNode,
  };

  let activeRequest = null;
  const coverLightbox = document.createElement("dialog");
  coverLightbox.className = "cover-lightbox";

  const coverLightboxInner = document.createElement("div");
  coverLightboxInner.className = "cover-lightbox__inner";

  const coverLightboxImage = document.createElement("img");
  coverLightboxImage.className = "cover-lightbox__image";
  coverLightboxImage.alt = "";

  const coverLightboxClose = document.createElement("button");
  coverLightboxClose.type = "button";
  coverLightboxClose.className = "cover-lightbox__close";
  coverLightboxClose.textContent = "關閉";

  coverLightboxInner.appendChild(coverLightboxImage);
  coverLightboxInner.appendChild(coverLightboxClose);
  coverLightbox.appendChild(coverLightboxInner);
  document.body.appendChild(coverLightbox);

  const closeCoverLightbox = () => {
    if (typeof coverLightbox.close === "function" && coverLightbox.open) {
      coverLightbox.close();
    } else {
      coverLightbox.removeAttribute("open");
    }

    coverLightboxImage.removeAttribute("src");
    coverLightboxImage.alt = "";
  };

  const openCoverLightbox = () => {
    if (!coverImageNode) {
      return;
    }

    const src = coverImageNode.getAttribute("src");
    if (!src) {
      return;
    }

    coverLightboxImage.src = src;
    coverLightboxImage.alt = coverImageNode.alt || "書籍封面大圖";

    if (typeof coverLightbox.showModal === "function" && !coverLightbox.open) {
      coverLightbox.showModal();
      return;
    }

    coverLightbox.setAttribute("open", "open");
  };

  const buildSearchUrl = (value) => {
    const normalizedValue = typeof value === "string" ? value.trim() : "";
    const action = searchForm?.getAttribute("action") || "index.php";
    const params = new URLSearchParams();

    if (normalizedValue !== "") {
      params.set("q", normalizedValue);
    }

    ["per_page", "sort", "direction"].forEach((name) => {
      const input = searchForm?.querySelector(`input[name="${name}"]`);
      const inputValue = input?.value?.trim();

      if (inputValue) {
        params.set(name, inputValue);
      }
    });

    const queryString = params.toString();

    return queryString ? `${action}?${queryString}` : action;
  };

  const isMobileLayout = () => window.matchMedia("(max-width: 900px)").matches;
  const isSearchShortcutEnabled = () => !isMobileLayout();

  const insertTextAtCursor = (input, text) => {
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    const before = input.value.slice(0, start);
    const after = input.value.slice(end);
    input.value = `${before}${text}${after}`;
    const cursor = start + text.length;
    input.setSelectionRange(cursor, cursor);
  };

  const buildTagExpression = (current, tag, operator = null) => {
    if (current === "") {
      return tag;
    }

    if (operator === "+") {
      return `${current} +${tag}`;
    }

    if (operator === "||") {
      return `${current} || ${tag}`;
    }

    if (operator === "-") {
      return `${current} -${tag}`;
    }

    return `${current} ${tag}`;
  };

  const logicPicker = document.createElement("div");
  logicPicker.className = "logic-picker";
  logicPicker.hidden = true;
  logicPicker.innerHTML = `
    <div class="logic-picker__panel" role="dialog" aria-label="邏輯條件選單">
      <div class="logic-picker__title">選擇邏輯條件</div>
      <div class="logic-picker__actions">
        <button type="button" class="logic-picker__btn" data-logic-value="+"><span class="logic-picker__symbol">+</span><span class="logic-picker__hint">Ctrl+1</span></button>
        <button type="button" class="logic-picker__btn" data-logic-value="-"><span class="logic-picker__symbol">-</span><span class="logic-picker__hint">Ctrl+2</span></button>
        <button type="button" class="logic-picker__btn" data-logic-value="||"><span class="logic-picker__symbol">||</span><span class="logic-picker__hint">Ctrl+3</span></button>
        <button type="button" class="logic-picker__btn" data-logic-value="("><span class="logic-picker__symbol">(</span><span class="logic-picker__hint">Ctrl+4</span></button>
        <button type="button" class="logic-picker__btn" data-logic-value=")"><span class="logic-picker__symbol">)</span><span class="logic-picker__hint">Ctrl+5</span></button>
      </div>
    </div>
  `;
  document.body.appendChild(logicPicker);

  let logicPickerSelectHandler = null;

  const closeLogicPicker = () => {
    logicPicker.hidden = true;
    logicPickerSelectHandler = null;
  };

  const openLogicPicker = (anchor, onSelect) => {
    if (!anchor) {
      return;
    }

    logicPickerSelectHandler = onSelect;
    logicPicker.hidden = false;

    const rect = anchor.getBoundingClientRect();
    const panel = logicPicker.querySelector(".logic-picker__panel");
    const panelWidth = panel ? panel.offsetWidth : 320;
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
    const left = Math.min(Math.max(10, rect.left), Math.max(10, viewportWidth - panelWidth - 10));
    const top = rect.bottom + 8;

    logicPicker.style.left = `${left}px`;
    logicPicker.style.top = `${top}px`;
  };

  logicPicker.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }

    const button = target.closest("[data-logic-value]");
    if (!button) {
      if (target === logicPicker) {
        closeLogicPicker();
      }
      return;
    }

    const value = button.getAttribute("data-logic-value");
    if (!value) {
      closeLogicPicker();
      return;
    }

    if (typeof logicPickerSelectHandler === "function") {
      logicPickerSelectHandler(value);
    }
    closeLogicPicker();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      const closeButton = findTopmostCloseButton();
      if (closeButton instanceof HTMLElement) {
        event.preventDefault();
        event.stopPropagation();
        closeButton.click();
        return;
      }
    }
  }, true);

  document.addEventListener("keydown", (event) => {
    if (!event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey) {
      if (!isTypingTarget(event.target) && !document.querySelector("dialog[open]")) {
        if (event.key === "ArrowLeft") {
          const previousUrl = findPagerNavigationUrl("previous");
          if (previousUrl) {
            event.preventDefault();
            window.location.href = previousUrl;
            return;
          }
        }

        if (event.key === "ArrowRight") {
          const nextUrl = findPagerNavigationUrl("next");
          if (nextUrl) {
            event.preventDefault();
            window.location.href = nextUrl;
            return;
          }
        }
      }
    }

    if (event.ctrlKey && !event.metaKey && !event.altKey && event.key === "Enter") {
      if (searchInput) {
        event.preventDefault();
        searchInput.focus();
        searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
      }
      return;
    }

    if (event.key === "Escape" && !logicPicker.hidden) {
      closeLogicPicker();
    }
  });

  document.addEventListener("click", (event) => {
    if (logicPicker.hidden) {
      return;
    }

    if (logicPicker.contains(event.target)) {
      return;
    }

    if (searchInput && searchInput.contains(event.target)) {
      return;
    }

    closeLogicPicker();
  });

  if (searchInput) {
    searchInput.addEventListener("keydown", (event) => {
      if (isSearchShortcutEnabled() && event.ctrlKey && !event.metaKey && !event.altKey) {
        if (event.key === "1") {
          event.preventDefault();
          insertTextAtCursor(searchInput, " +");
          return;
        }

        if (event.key === "2") {
          event.preventDefault();
          insertTextAtCursor(searchInput, " -");
          return;
        }

        if (event.key === "3") {
          event.preventDefault();
          insertTextAtCursor(searchInput, " || ");
          return;
        }

        if (event.key === "4") {
          event.preventDefault();
          insertTextAtCursor(searchInput, "(");
          return;
        }

        if (event.key === "5") {
          event.preventDefault();
          insertTextAtCursor(searchInput, ")");
          return;
        }
      }

      if (!isSearchShortcutEnabled()) {
        return;
      }

      if (event.key !== " " || event.ctrlKey || event.metaKey || event.altKey || event.isComposing) {
        return;
      }

      event.preventDefault();
      openLogicPicker(searchInput, (operator) => {
        const insertion = operator === "||" ? " || " : ` ${operator}`;
        insertTextAtCursor(searchInput, insertion);
        searchInput.focus();
      });
    });
  }

  const showDialog = () => {
    if (typeof dialog.showModal === "function" && !dialog.open) {
      dialog.showModal();
      document.body.classList.add("dialog-open");
      return;
    }

    dialog.setAttribute("open", "open");
    document.body.classList.add("dialog-open");
  };

  const closeDialog = () => {
    if (activeRequest) {
      activeRequest.abort();
      activeRequest = null;
    }

    closeCoverLightbox();

    if (typeof dialog.close === "function" && dialog.open) {
      dialog.close();
      document.body.classList.remove("dialog-open");
      return;
    }

    dialog.removeAttribute("open");
    document.body.classList.remove("dialog-open");
  };

  const setStatus = (message) => {
    if (!statusNode) {
      return;
    }

    if (!message) {
      statusNode.textContent = "";
      statusNode.hidden = true;
      return;
    }

    statusNode.textContent = message;
    statusNode.hidden = false;
  };

  const setMetadataValue = (node, value) => {
    if (!node) {
      return;
    }

    node.textContent = value && value.trim() !== "" ? value : "未提供";
  };

  const renderSearchLinks = (node, values, linkClassName) => {
    if (!node) {
      return;
    }

    const items = Array.isArray(values)
      ? values
        .map((value) => (typeof value === "string" ? value.trim() : ""))
        .filter((value, index, array) => value !== "" && array.indexOf(value) === index)
      : [];

    node.replaceChildren();

    if (items.length === 0) {
      node.textContent = "未提供";
      return;
    }

    const fragment = document.createDocumentFragment();

    items.forEach((value, index) => {
      const link = document.createElement("a");
      link.className = linkClassName;
      link.href = buildSearchUrl(value);
      link.textContent = value;
      fragment.appendChild(link);

      if (index < items.length - 1) {
        fragment.appendChild(document.createTextNode(", "));
      }
    });

    node.appendChild(fragment);
  };

  const setCover = (book) => {
    if (!coverNode || !coverImageNode) {
      return;
    }

    const coverUrl = typeof book.cover_url === "string" ? book.cover_url.trim() : "";
    const coverTitle = typeof book.title === "string" && book.title.trim() !== ""
      ? `${book.title.trim()} 封面`
      : "書籍封面";

    if (coverUrl === "") {
      coverImageNode.removeAttribute("src");
      coverImageNode.alt = "";
      coverImageNode.setAttribute("aria-hidden", "true");
      coverNode.hidden = true;
      return;
    }

    coverImageNode.src = coverUrl;
    coverImageNode.alt = coverTitle;
    coverImageNode.setAttribute("aria-hidden", "false");
    coverNode.hidden = false;
  };

  const setDownloadLink = (book) => {
    if (!downloadLink) {
      return;
    }

    const directUrl = typeof book?.download_url === "string" ? book.download_url.trim() : "";
    if (directUrl !== "") {
      downloadLink.hidden = false;
      downloadLink.setAttribute("href", directUrl);
      return;
    }

    const bookId = Number.parseInt(book?.id, 10);
    if (!Number.isInteger(bookId) || bookId <= 0) {
      downloadLink.hidden = true;
      downloadLink.setAttribute("href", "#");
      return;
    }

    downloadLink.hidden = false;
    downloadLink.setAttribute("href", `download.php?id=${bookId}`);
  };

  const setReadLink = (book) => {
    if (!readLink) {
      return;
    }

    const directUrl = typeof book?.read_url === "string" ? book.read_url.trim() : "";
    if (directUrl !== "") {
      const backUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
      const resolvedUrl = new URL(directUrl, window.location.href);
      if (!resolvedUrl.searchParams.has("back")) {
        resolvedUrl.searchParams.set("back", backUrl);
      }
      readLink.hidden = false;
      readLink.setAttribute("href", resolvedUrl.toString());
      return;
    }

    readLink.hidden = true;
    readLink.setAttribute("href", "#");
  };

  const setSendLink = (book) => {
    if (!sendLink || !canSendBookByEmail) {
      if (sendLink) {
        sendLink.hidden = true;
        sendLink.setAttribute("href", "#");
      }
      return;
    }

    const directUrl = typeof book?.send_url === "string" ? book.send_url.trim() : "";
    if (directUrl !== "") {
      sendLink.hidden = false;
      sendLink.setAttribute("href", directUrl);
      return;
    }

    sendLink.hidden = true;
    sendLink.setAttribute("href", "#");
  };

  const fillDialog = (book) => {
    titleNode.textContent = book.title || "未命名書籍";
    renderSearchLinks(metadataNodes.author, book.authors || [], "author-link");
    renderSearchLinks(metadataNodes.tag, book.tags || [], "tag-link");
    renderSearchLinks(metadataNodes.series, book.series ? [book.series] : [], "series-link");
    setMetadataValue(metadataNodes.isbn, book.isbn || "");
    setMetadataValue(metadataNodes.publisher, book.publisher || "");
    setMetadataValue(metadataNodes.language, book.language || "");
    setCover(book);
    setReadLink(book);
    setDownloadLink(book);
    setSendLink(book);
    descriptionNode.textContent = book.description && book.description.trim() !== ""
      ? book.description
      : "目前沒有簡介資料。";
  };

  const prepareLoadingState = (trigger) => {
    const triggerText = trigger.textContent ? trigger.textContent.trim() : "";
    const triggerTitle = trigger.getAttribute("data-book-title") || trigger.getAttribute("aria-label") || "";
    titleNode.textContent = triggerText || triggerTitle || "書籍簡介";
    setMetadataValue(metadataNodes.author, "");
    setMetadataValue(metadataNodes.tag, "");
    setMetadataValue(metadataNodes.series, "");
    setMetadataValue(metadataNodes.isbn, "");
    setMetadataValue(metadataNodes.publisher, "");
    setMetadataValue(metadataNodes.language, "");
    setCover({});
    setReadLink({});
    setDownloadLink({});
    setSendLink({});
    descriptionNode.textContent = "載入中...";
    setStatus("");
  };

  document.querySelectorAll("[data-book-details-url]").forEach((trigger) => {
    trigger.addEventListener("click", async (event) => {
      const detailsUrl = trigger.getAttribute("data-book-details-url");
      if (!detailsUrl) {
        return;
      }

      event.preventDefault();

      if (activeRequest) {
        activeRequest.abort();
        activeRequest = null;
      }

      prepareLoadingState(trigger);
      showDialog();

      if (typeof AbortController === "function") {
        activeRequest = new AbortController();
      }

      try {
        const response = await fetch(detailsUrl, {
          headers: {
            Accept: "application/json",
          },
          signal: activeRequest ? activeRequest.signal : undefined,
        });

        if (!response.ok) {
          throw new Error("無法載入書籍簡介。");
        }

        const book = await response.json();
        if (book.error) {
          throw new Error(book.error);
        }

        fillDialog(book);
      } catch (error) {
        if (error && error.name === "AbortError") {
          return;
        }

        descriptionNode.textContent = "目前無法取得書籍簡介。";
        setDownloadLink({});
        setSendLink({});
        setStatus(error instanceof Error ? error.message : "目前無法取得書籍簡介。");
      } finally {
        activeRequest = null;
      }
    });
  });

  if (closeButton) {
    closeButton.addEventListener("click", closeDialog);
  }

  dialog.addEventListener("cancel", () => {
    if (activeRequest) {
      activeRequest.abort();
      activeRequest = null;
    }
    document.body.classList.remove("dialog-open");
  });

  dialog.addEventListener("close", () => {
    document.body.classList.remove("dialog-open");
  });

  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) {
      closeDialog();
    }
  });

  if (coverImageNode) {
    coverImageNode.setAttribute("tabindex", "0");
    coverImageNode.setAttribute("role", "button");
    coverImageNode.setAttribute("aria-label", "點擊放大封面");

    coverImageNode.addEventListener("click", () => {
      openCoverLightbox();
    });

    coverImageNode.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") {
        return;
      }

      event.preventDefault();
      openCoverLightbox();
    });

    coverImageNode.addEventListener("error", () => {
      if (coverNode) {
        coverNode.hidden = true;
      }
      coverImageNode.removeAttribute("src");
      coverImageNode.alt = "";
      closeCoverLightbox();
    });
  }

  coverLightboxClose.addEventListener("click", closeCoverLightbox);

  coverLightbox.addEventListener("click", (event) => {
    if (event.target === coverLightbox) {
      closeCoverLightbox();
    }
  });

  coverLightbox.addEventListener("cancel", () => {
    closeCoverLightbox();
  });

  document.querySelectorAll("[data-read-toggle-url]").forEach((checkbox) => {
    checkbox.addEventListener("change", async () => {
      const toggleUrl = checkbox.getAttribute("data-read-toggle-url");
      const bookId = checkbox.getAttribute("data-read-book-id");
      const checked = checkbox.checked;

      if (!toggleUrl || !bookId) {
        return;
      }

      checkbox.disabled = true;

      try {
        const payload = new URLSearchParams();
        payload.set("id", bookId);
        payload.set("is_read", checked ? "1" : "0");

        const response = await fetch(toggleUrl, {
          method: "POST",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          },
          body: payload.toString(),
        });

        if (!response.ok) {
          throw new Error("無法更新已閱讀狀態。");
        }

        const result = await response.json();
        if (!result.ok) {
          throw new Error(result.error || "無法更新已閱讀狀態。");
        }
      } catch (error) {
        checkbox.checked = !checked;
        window.alert(error instanceof Error ? error.message : "無法更新已閱讀狀態。");
      } finally {
        checkbox.disabled = false;
      }
    });
  });

  document.querySelectorAll("[data-fill-search]").forEach((link) => {
    link.addEventListener("click", (event) => {
      if (!searchInput) {
        return;
      }

      event.preventDefault();

      const value = link.getAttribute("data-fill-search");
      if (!value) {
        return;
      }

      const current = searchInput.value.trim();
      const normalizedValue = value.trim();
      if (normalizedValue === "") {
        return;
      }

      if (current.includes(normalizedValue)) {
        searchInput.focus();
        searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        return;
      }

      if (isMobileLayout() && current !== "" && isSearchShortcutEnabled()) {
        openLogicPicker(link, (operator) => {
          searchInput.value = buildTagExpression(current, normalizedValue, operator);
          searchInput.focus();
          searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        });
        return;
      }

      searchInput.value = buildTagExpression(current, normalizedValue);
      searchInput.focus();
      searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
    });
  });

  const hoverPreview = document.createElement("div");
  hoverPreview.className = "cover-hover-preview";
  hoverPreview.hidden = true;

  const hoverPreviewImage = document.createElement("img");
  hoverPreviewImage.className = "cover-hover-preview__image";
  hoverPreviewImage.alt = "";
  hoverPreview.appendChild(hoverPreviewImage);

  document.body.appendChild(hoverPreview);

  let activeHoverLink = null;
  let hoverHideTimer = null;

  const hideHoverPreview = () => {
    if (hoverHideTimer) {
      window.clearTimeout(hoverHideTimer);
      hoverHideTimer = null;
    }

    activeHoverLink = null;
    hoverPreview.hidden = true;
    hoverPreviewImage.removeAttribute("src");
    hoverPreviewImage.alt = "";
  };

  const positionHoverPreview = (event) => {
    const offset = 12;
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;

    const previewRect = hoverPreview.getBoundingClientRect();
    let left = event.clientX + offset;
    let top = event.clientY + offset;

    if (left + previewRect.width > viewportWidth - 8) {
      left = event.clientX - previewRect.width - offset;
    }

    if (top + previewRect.height > viewportHeight - 8) {
      top = event.clientY - previewRect.height - offset;
    }

    hoverPreview.style.left = `${Math.max(8, left)}px`;
    hoverPreview.style.top = `${Math.max(8, top)}px`;
  };

  const showHoverPreview = (link, event) => {
    const coverUrl = link.getAttribute("data-hover-cover-url");
    if (!coverUrl) {
      hideHoverPreview();
      return;
    }

    if (hoverHideTimer) {
      window.clearTimeout(hoverHideTimer);
      hoverHideTimer = null;
    }

    activeHoverLink = link;
    hoverPreviewImage.src = coverUrl;
    hoverPreviewImage.alt = `${link.textContent?.trim() || "書籍"} 封面預覽`;
    hoverPreview.hidden = false;
    positionHoverPreview(event);
  };

  hoverPreviewImage.addEventListener("error", hideHoverPreview);

  document.querySelectorAll(".title-link[data-hover-cover-url]").forEach((link) => {
    link.addEventListener("mouseenter", (event) => {
      showHoverPreview(link, event);
    });

    link.addEventListener("mousemove", (event) => {
      if (activeHoverLink !== link || hoverPreview.hidden) {
        return;
      }

      positionHoverPreview(event);
    });

    link.addEventListener("mouseleave", () => {
      hoverHideTimer = window.setTimeout(() => {
        hideHoverPreview();
      }, 30);
    });
  });

})();
