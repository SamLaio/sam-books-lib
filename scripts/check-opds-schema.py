"""Validate generated feeds against upstream OPDS 1.2 + RFC 4287 schemas.

Requires Python lxml and Java (Trang is downloaded into the fixture directory).
Usage: python scripts/check-opds-schema.py FIXTURE_DIRECTORY
"""

import pathlib
import subprocess
import sys
import urllib.request

from lxml import etree


def validate(directory):
    directory = pathlib.Path(directory)
    schema_dir = directory / "schema"
    schema_dir.mkdir(exist_ok=True)
    opds = urllib.request.urlopen(
        "https://raw.githubusercontent.com/opds-community/specs/master/schema/1.2/opds.rnc", timeout=30
    ).read()
    (schema_dir / "opds.rnc").write_bytes(opds)
    # Upstream writes \nrt, which accidentally forbids literal 'r' and 't'
    # (including every http URL). Keep the original and correct only the typo.
    fixed = opds.replace(br'\nrt', br'\n\r\t')
    (schema_dir / "opds-iri-fixed.rnc").write_bytes(fixed)
    rfc = urllib.request.urlopen("https://www.rfc-editor.org/rfc/rfc4287.txt", timeout=30).read().decode()
    appendix = rfc[rfc.index("   # -*- rnc -*-"):]
    appendix = appendix[:appendix.index("   # EOF") + len("   # EOF")]
    # RFC pagination headers/footers are outside the indented schema source.
    lines = [line[3:] for line in appendix.splitlines() if line.startswith("   ") or not line.strip()]
    (schema_dir / "atom.rnc").write_text("\n".join(lines), encoding="utf-8")
    trang = schema_dir / 'trang.jar'
    trang.write_bytes(urllib.request.urlopen(
        'https://repo.maven.apache.org/maven2/org/relaxng/trang/20241231/trang-20241231.jar', timeout=30
    ).read())
    subprocess.run(['java', '-jar', str(trang), str(schema_dir / 'opds-iri-fixed.rnc'), str(schema_dir / 'opds.rng')], check=True)
    schema = etree.RelaxNG(etree.parse(str(schema_dir / 'opds.rng')))
    count = 0
    parser = etree.XMLParser(resolve_entities=False, no_network=True)
    for path in sorted(directory.glob("*.xml")):
        document = etree.parse(str(path), parser)
        if document.getroot().tag != "{http://www.w3.org/2005/Atom}feed":
            continue  # OpenSearch has its own namespace and contract checks.
        if not schema.validate(document):
            raise AssertionError(f"{path.name}: {schema.error_log}")
        count += 1
    if not count:
        raise AssertionError("No Atom feeds were found")
    print(f"PASS: {count} feeds validated against OPDS 1.2 + RFC 4287 RELAX NG (upstream IRI regex typo corrected)")


if __name__ == "__main__":
    validate(sys.argv[1])
