#!/usr/bin/env sh
set -eu

usage() {
    cat <<'USAGE'
Usage: reflection-file-list.sh [options] [PATTERN ...]

Create a newline-delimited file list for Reflection Farm Master bulk import.

Run without arguments in a terminal to get a guided setup.
All prompts can be skipped by passing the needed options directly.

Options:
  -r, --root DIR       Directory to scan.
                       Prompted in guided mode. Default: current directory.
  -b, --base DIR       Make output paths relative to DIR.
                       Prompted in guided mode. Default: same as --root.
  -o, --output FILE    Write list to FILE instead of stdout.
                       Prompted in guided mode. Leave blank for stdout.
  -a, --all            Include all files.
                       Default in non-interactive mode when no PATTERN is given.
  -i, --interactive    Ask for any missing information.
      --no-prompt      Never prompt. Use defaults and command-line options only.
  -v, --verbose        Print a small summary to stderr.
  -h, --help           Show this help.

Patterns are shell-style file-name patterns. Quote them so your shell does not
expand them before this script sees them, for example:
  'img*.png'
  '*.mp4'
  'frame_????.exr'

Examples:
  tools/reflection-file-list.sh
  tools/reflection-file-list.sh -r incoming '*.png' '*.jpg' -o images.list
  tools/reflection-file-list.sh -r incoming -b incoming -a -o import.list
  tools/reflection-file-list.sh --no-prompt -r incoming '*.mp4' > mp4.list
USAGE
}

say() {
    printf '%s\n' "$*" >&2
}

die() {
    say "ERROR: $*"
    exit 2
}

append_pattern() {
    patterns=${patterns}${patterns:+
}$1
}

prompt_default() {
    prompt_text=$1
    default_value=$2
    answer=""

    if [ -n "$default_value" ]; then
        printf '%s [%s]: ' "$prompt_text" "$default_value" >&2
    else
        printf '%s: ' "$prompt_text" >&2
    fi

    IFS= read -r answer || answer=""
    if [ -z "$answer" ]; then
        printf '%s\n' "$default_value"
    else
        printf '%s\n' "$answer"
    fi
}

prompt_optional() {
    prompt_text=$1
    answer=""
    printf '%s: ' "$prompt_text" >&2
    IFS= read -r answer || answer=""
    printf '%s\n' "$answer"
}

prompt_yes_no() {
    prompt_text=$1
    default_answer=$2

    while :; do
        case "$default_answer" in
            y|Y) printf '%s [Y/n]: ' "$prompt_text" >&2 ;;
            n|N) printf '%s [y/N]: ' "$prompt_text" >&2 ;;
            *) printf '%s [y/n]: ' "$prompt_text" >&2 ;;
        esac

        answer=""
        IFS= read -r answer || answer=""
        if [ -z "$answer" ]; then
            answer=$default_answer
        fi

        case "$answer" in
            y|Y|yes|YES|Yes) return 0 ;;
            n|N|no|NO|No) return 1 ;;
            *) say "Please answer y or n." ;;
        esac
    done
}

abs_dir() {
    [ -d "$1" ] || return 1
    (cd "$1" && pwd -P)
}

abs_file_path() {
    file_path=$1
    file_dir=$(dirname "$file_path")
    file_name=$(basename "$file_path")
    [ -d "$file_dir" ] || return 1
    (cd "$file_dir" && printf '%s/%s\n' "$(pwd -P)" "$file_name")
}

add_patterns_from_words() {
    words=$1
    set -f
    # shellcheck disable=SC2086
    for word in $words; do
        append_pattern "$word"
    done
    set +f
}

matches_patterns() {
    file_name=$1

    if [ "$all" -eq 1 ] || [ -z "$patterns" ]; then
        return 0
    fi

    old_ifs=$IFS
    IFS='
'
    for pattern in $patterns; do
        case "$file_name" in
            $pattern) IFS=$old_ifs; return 0 ;;
        esac
    done
    IFS=$old_ifs
    return 1
}

make_list() {
    find "$root_abs" -type f -print | while IFS= read -r path; do
        [ -n "$output_abs" ] && [ "$path" = "$output_abs" ] && continue
        [ -n "$temp_abs" ] && [ "$path" = "$temp_abs" ] && continue

        name=${path##*/}
        if matches_patterns "$name"; then
            display_path=$path
            case "$display_path" in
                "$base_abs"/*) display_path=${display_path#"$base_abs"/} ;;
            esac
            printf '%s\n' "$display_path"
        fi
    done | sort
}

root="."
base=""
output=""
all=0
patterns=""
verbose=0
prompt_mode="auto"
root_set=0
base_set=0
output_set=0
mode_set=0
had_arguments=0

[ "$#" -gt 0 ] && had_arguments=1

while [ "$#" -gt 0 ]; do
    case "$1" in
        -r|--root)
            [ "$#" -gt 1 ] || die "Missing value for $1"
            root=$2
            root_set=1
            shift 2
            ;;
        -b|--base)
            [ "$#" -gt 1 ] || die "Missing value for $1"
            base=$2
            base_set=1
            shift 2
            ;;
        -o|--output)
            [ "$#" -gt 1 ] || die "Missing value for $1"
            output=$2
            output_set=1
            shift 2
            ;;
        -a|--all)
            all=1
            mode_set=1
            shift
            ;;
        -i|--interactive)
            prompt_mode="yes"
            shift
            ;;
        --no-prompt)
            prompt_mode="no"
            shift
            ;;
        -v|--verbose)
            verbose=1
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
            say "Unknown option: $1"
            usage >&2
            exit 2
            ;;
        *)
            append_pattern "$1"
            mode_set=1
            shift
            ;;
    esac
done

while [ "$#" -gt 0 ]; do
    append_pattern "$1"
    mode_set=1
    shift
done

should_prompt=0
case "$prompt_mode" in
    yes)
        should_prompt=1
        ;;
    auto)
        if [ "$had_arguments" -eq 0 ] && [ -t 0 ] && [ -t 1 ]; then
            should_prompt=1
        fi
        ;;
    no)
        should_prompt=0
        ;;
esac

if [ "$should_prompt" -eq 1 ]; then
    verbose=1
    say "Reflection file list setup"
    say "Press Enter to accept the value in brackets."
    say ""

    if [ "$root_set" -eq 0 ]; then
        while :; do
            root=$(prompt_default "Directory to scan" ".")
            if [ -d "$root" ]; then
                break
            fi
            say "That directory does not exist: $root"
        done
    fi

    if [ "$base_set" -eq 0 ]; then
        base_default=$root
        while :; do
            base=$(prompt_default "Make paths relative to this directory" "$base_default")
            if [ -d "$base" ]; then
                break
            fi
            say "That directory does not exist: $base"
        done
    fi

    if [ "$output_set" -eq 0 ]; then
        output=$(prompt_optional "Output file, or leave blank to print to screen")
    fi

    if [ "$mode_set" -eq 0 ]; then
        if prompt_yes_no "Include all files" "y"; then
            all=1
        else
            while [ -z "$patterns" ]; do
                pattern_line=$(prompt_default "File patterns separated by spaces, for example '*.png' '*.mp4'" "*.*")
                add_patterns_from_words "$pattern_line"
            done
        fi
    fi
fi

if [ -z "$base" ]; then
    if [ "$base_set" -eq 1 ]; then
        base=""
    else
        base="."
    fi
fi

[ -d "$root" ] || die "Root directory does not exist: $root"
root_abs=$(abs_dir "$root") || die "Cannot read root directory: $root"

if [ -n "$base" ]; then
    [ -d "$base" ] || die "Base directory does not exist: $base"
    base_abs=$(abs_dir "$base") || die "Cannot read base directory: $base"
else
    base_abs=""
fi

output_abs=""
temp_abs=""
temp_file=""

if [ -n "$output" ]; then
    [ ! -d "$output" ] || die "Output path is a directory. Please give a file name: $output"

    output_dir=$(dirname "$output")
    if [ ! -d "$output_dir" ]; then
        if [ "$should_prompt" -eq 1 ] && prompt_yes_no "Output directory does not exist. Create it" "y"; then
            mkdir -p "$output_dir" || die "Could not create output directory: $output_dir"
        else
            die "Output directory does not exist: $output_dir"
        fi
    fi

    output_abs=$(abs_file_path "$output") || die "Could not resolve output path: $output"
    temp_file="${output}.tmp.$$"
    : > "$temp_file" || die "Could not create temporary output file: $temp_file"
    temp_abs=$(abs_file_path "$temp_file") || die "Could not resolve temporary output path: $temp_file"
    trap 'rm -f "$temp_file"' EXIT HUP INT TERM

    make_list > "$temp_file"
    mv "$temp_file" "$output"
    trap - EXIT HUP INT TERM

    if [ "$verbose" -eq 1 ]; then
        count=$(wc -l < "$output" | tr -d ' ')
        say "Wrote $count file path(s) to: $output"
    fi
else
    make_list
fi

