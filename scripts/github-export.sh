#!/usr/bin/env bash
#
# Publish a sanitized, history-free snapshot of HEAD to the public GitHub repo.
#
#   scripts/github-export.sh --dry-run   # build + check, print the result, no push
#   scripts/github-export.sh             # build + check + commit + push
#
# What it does:
#   1. clones the GitHub repo (its own, public history — never the private one),
#   2. replaces its contents with `git archive HEAD` of this repo,
#   3. deletes the files listed in EXCLUDE (private hosting notes etc.),
#   4. strips every block between a "# BEGIN site-specific" and a
#      "# END site-specific" comment line from any file (used for the
#      production redirect in public/.htaccess),
#   5. refuses to continue if any regex from .github-export-deny (a private,
#      untracked-by-the-export file, one extended regex per line, # comments)
#      still matches a file in the export,
#   6. commits "Krotze <version>" on top of the GitHub history and pushes.
#
# HEAD is exported, not the working tree: commit first.
# Override the target with GITHUB_REMOTE=<url>.

set -euo pipefail

REMOTE="${GITHUB_REMOTE:-https://github.com/michaelpapesch/krotze.git}"
EXCLUDE=(HOSTING.md .github-export-deny)
MARK_BEGIN='BEGIN site-specific'
MARK_END='END site-specific'
DENY_FILE='.github-export-deny'

dry=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) dry=1 ;;
        -h|--help) sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

root=$(git rev-parse --show-toplevel)
cd "$root"

if [ -n "$(git status --porcelain)" ]; then
    echo "note: working tree is dirty; HEAD ($(git rev-parse --short HEAD)) is exported, not the working tree." >&2
fi

version=$(grep -oE "'version' => '[^']+'" config/app.php | grep -oE '[0-9][0-9.]*' || true)
[ -n "$version" ] || { echo "could not read 'version' from config/app.php" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
repo="$work/repo"

echo "-> cloning $REMOTE"
git clone -q "$REMOTE" "$repo" 2>&1 | grep -v 'cloned an empty repository' || true
[ -d "$repo/.git" ] || { echo "clone failed" >&2; exit 1; }

# Wipe everything but .git, then unpack HEAD into it.
find "$repo" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
git archive HEAD | tar -x -C "$repo"

for f in "${EXCLUDE[@]}"; do
    rm -rf "${repo:?}/$f"
done

# Strip marker blocks. Only whole marker lines count (a comment consisting of
# the marker), so mentioning the markers in prose or in this script is harmless.
begin_re="^[[:space:]]*#[[:space:]]*$MARK_BEGIN"
end_re="^[[:space:]]*#[[:space:]]*$MARK_END"
grep -rlE --exclude-dir=.git "$begin_re" "$repo" | while IFS= read -r file; do
    awk -v b="$begin_re" -v e="$end_re" '
        $0 ~ b { skip = 1; next }
        $0 ~ e { skip = 0; eatblank = 1; next }
        skip { next }
        eatblank && /^[[:space:]]*$/ { eatblank = 0; next }
        { eatblank = 0; print }
    ' "$file" > "$file.tmp" && mv "$file.tmp" "$file"
    echo "-> stripped site-specific block(s) from ${file#$repo/}"
done
if grep -rqE --exclude-dir=.git "$end_re" "$repo"; then
    echo "an END marker survived (unbalanced markers?):" >&2
    grep -rnE --exclude-dir=.git "$end_re" "$repo" >&2
    exit 1
fi

# Deny-list check.
if [ -f "$DENY_FILE" ]; then
    patterns=$(grep -vE '^\s*(#|$)' "$DENY_FILE" || true)
    if [ -n "$patterns" ]; then
        if hits=$(grep -rnIE --exclude-dir=.git -f <(printf '%s\n' "$patterns") "$repo"); then
            echo "REFUSING: deny-listed content in the export:" >&2
            printf '%s\n' "$hits" | sed "s#^$repo/##" | cut -c1-200 >&2
            exit 1
        fi
    fi
    echo "-> deny-list clean ($(printf '%s\n' "$patterns" | wc -l | tr -d ' ') patterns)"
else
    echo "warning: no $DENY_FILE found, skipping the deny-list check" >&2
fi

cd "$repo"
git add -A
if git diff --cached --quiet; then
    echo "nothing changed since the last export."
    exit 0
fi

git commit -q -m "Krotze $version"
echo "-> commit $(git rev-parse --short HEAD): Krotze $version"
git show --stat --format= HEAD | tail -n 25

if [ "$dry" = 1 ]; then
    echo "dry run: nothing pushed."
    exit 0
fi

# Pin the GitHub account so a credential manager holding several does not pick another.
owner=$(printf '%s' "$REMOTE" | sed -E 's#.*github\.com[:/]([^/]+)/.*#\1#')
if [ "$owner" != "$REMOTE" ]; then
    git config "credential.https://github.com.username" "$owner"
fi

echo "-> pushing to $REMOTE"
git push -q origin HEAD:main
echo "done."
