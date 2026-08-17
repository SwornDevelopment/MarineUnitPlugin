#!/usr/bin/env bash
#
# Build the installable plugin zip.
#
#   ./bin/build.sh
#
# Produces dist/marine-unit-plugin-<version>.zip, containing a single
# marine-unit-plugin/ directory so it installs cleanly via
# Plugins -> Add New -> Upload Plugin.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="marine-unit-plugin"
DIST="${ROOT}/dist"
STAGE="${DIST}/${SLUG}"

VERSION="$(grep -m1 -oP "^\s*\*\s*Version:\s*\K[0-9.]+" "${ROOT}/${SLUG}.php")"

if [[ -z "${VERSION}" ]]; then
	echo "Could not read the version from ${SLUG}.php" >&2
	exit 1
fi

echo "Building ${SLUG} ${VERSION}"

# Lint everything before packaging — a parse error must never ship.
FAILED=0
while IFS= read -r file; do
	if ! php -l "${file}" >/dev/null 2>&1; then
		echo "Syntax error: ${file}" >&2
		php -l "${file}" >&2 || true
		FAILED=1
	fi
done < <(find "${ROOT}" -name '*.php' -not -path '*/.git/*' -not -path '*/dist/*')

if [[ "${FAILED}" -ne 0 ]]; then
	echo "Build aborted: fix the syntax errors above." >&2
	exit 1
fi

for suite in "${ROOT}"/tests/*.php; do
	if ! php "${suite}" >/dev/null; then
		echo "Build aborted: $(basename "${suite}") failed. Run it directly to see why." >&2
		exit 1
	fi
done

rm -rf "${STAGE}"
mkdir -p "${STAGE}"

# Ship only what the plugin needs at runtime.
for item in \
	"${SLUG}.php" \
	uninstall.php \
	includes \
	assets \
	languages \
	vendor
do
	if [[ -e "${ROOT}/${item}" ]]; then
		cp -R "${ROOT}/${item}" "${STAGE}/"
	fi
done

ZIP="${DIST}/${SLUG}-${VERSION}.zip"
rm -f "${ZIP}"

( cd "${DIST}" && zip -rq "$(basename "${ZIP}")" "${SLUG}" -x '*.DS_Store' )

rm -rf "${STAGE}"

echo "Built ${ZIP}"
