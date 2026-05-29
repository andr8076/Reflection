#!/usr/bin/env sh
set -eu

usage() {
    cat <<'USAGE'
Usage: reflection-file-list.sh [options] [PATTERN ...]

Create a newline-delimited file list for Reflection Farm Master bulk import.

Options:
  -r, --root DIR       Directory to scan. Default: current directory.
  -b, --base DIR       Make output paths relative to DIR. Default: current directory.
  -o, --output FILE    Write list to FILE instead of stdout.
  -a, --all            Include all files. Default when no PATTERN is provided.
  -h, --help           Show this help.

Patterns are shell-style file-name patterns matched by find -name, for example:
  img*.png
  *.mp4
  frame_????.exr

Examples:
  tools/reflection-file-list.sh -r incoming 'img*.png' > import.list
  tools/reflection-file-list.sh -r incoming '*.mp4' -o mp4.list
  tools/reflection-file-list.sh -r /volume1/web/api/farm/incoming -b /volume1/web/api/farm --all

Tip: quote patterns so your shell does not expand them before this tool runs.
USAGE
}

root="."
base="."
output=""
all=0
patterns=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        -r|--root)
            [ "$#" -gt 1 ] || { echo "Missing value for $1" >&2; exit 2; }
            root=$2
            shift 2
            ;;
        -b|--base)
            [ "$#" -gt 1 ] || { echo "Missing value for $1" >&2; exit 2; }
            base=$2
            shift 2
            ;;
        -o|--output)
            [ "$#" -gt 1 ] || { echo "Missing value for $1" >&2; exit 2; }
            output=$2
            shift 2
            ;;
        -a|--all)
            all=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        --)
            shift
            break
            ;;
        -* )
            echo "Unknown option: $1" >&2
            usage >&2
            exit 2
            ;;
        *)
            patterns=${patterns}${patterns:+
}$1
            shift
            ;;
    esac
done

while [ "$#" -gt 0 ]; do
    patterns=${patterns}${patterns:+
}$1
    shift
done

[ -d "$root" ] || { echo "Root directory does not exist: $root" >&2; exit 2; }
make_list() {
    if [ "$all" -eq 1 ] || [ -z "$patterns" ]; then
        find "$root" -type f -print
    else
        first=1
        find_expr=""
        old_ifs=$IFS
        IFS='
'
        for pattern in $patterns; do
            if [ "$first" -eq 1 ]; then
                find_expr="-name|$pattern"
                first=0
            else
                find_expr="$find_expr|-o|-name|$pattern"
            fi
        done
        IFS=$old_ifs

        # Convert the pipe-separated expression into positional parameters so
        # POSIX sh can pass a dynamic number of find arguments safely enough for
        # simple glob patterns.
        old_ifs=$IFS
        IFS='|'
        # shellcheck disable=SC2086
        set -- $find_expr
        IFS=$old_ifs
        find "$root" -type f \( "$@" \) -print
    fi | awk -v base="$base" '
        BEGIN {
            gsub(/\\/, "/", base)
            sub(/\/$/, "", base)
        }
        {
            path = $0
            gsub(/\\/, "/", path)
            if (base != "" && index(path, base "/") == 1) {
                path = substr(path, length(base) + 2)
            }
            sub(/^\.\//, "", path)
            print path
        }
    ' | sort
}

if [ -n "$output" ]; then
    make_list > "$output"
else
    make_list
fi
