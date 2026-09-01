#!/usr/bin/env bash
#
# absorb-history.sh -- bring an absorbed standalone plugin's git history into the
# host plugin's repository, and merge later batches of the standalone's commits.
#
# Why each step is what it is:
# https://github.com/stellarwp/plugin-absorber/blob/main/docs/git-history.md

set -euo pipefail

# Keep in step with "version" in .claude-plugin/plugin.json. CI asserts they match.
VERSION='1.0.0'

PLUGIN_JSON_URL='https://raw.githubusercontent.com/stellarwp/plugin-absorber/main/.claude-plugin/plugin.json'

# Somewhere to set the standalone's whole tree aside under a name nothing uses.
# Renaming that one directory into place afterwards does not care whether the
# destination sits under a directory the standalone tracks itself.
STAGING_DIR='__absorb-import'

command_name=''
repo_url=''
into=''
ref=''
host_branch=''
remote_name=''
display_name=''
dry_run=0
assume_yes=0
update_check=1

# Rollback bookkeeping. Each is set the moment this run creates the thing named,
# so the trap can unwind exactly what this run did and nothing that was already
# there.
original_branch=''
created_host_branch=''
created_remote=''
created_worktree=''
created_import_branch=''
import_branch=''
scratch_parent=''
rollback_armed=0

say() {
	printf '%s\n' "$*"
}

note() {
	printf '%s\n' "$*" >&2
}

die() {
	printf 'absorb-history: %s\n' "$*" >&2
	exit 1
}

usage() {
	cat <<'USAGE'
absorb-history -- bring an absorbed standalone plugin's git history into the host
plugin's repository.

Usage:
  absorb-history.sh import --repo <url> --into <dir> [options]
  absorb-history.sh sync --into <dir> [options]
  absorb-history.sh --version
  absorb-history.sh --help

Commands:
  import    First run. Nests the standalone's whole tree under <dir> on a branch
            of its own, then merges it in as an unrelated history.
  sync      Every run after that. Merges the standalone's later commits with
            -X subtree, so they arrive already nested under <dir>.

Options:
  --repo <url>      The standalone's repository. Required for import; for sync,
                    only when the remote is not already configured.
  --into <dir>      Where the standalone's tree lands, relative to the host
                    repository root. For example sub-plugins/give-recurring.
  --ref <name>      Branch or tag to take. Defaults to the standalone's HEAD.
  --branch <name>   Host branch to create. Defaults to absorb-<basename of --into>.
  --remote <name>   Name for the standalone's remote. Same default.
  --name <text>     How the standalone is named in the commit message. Defaults
                    to the basename of --into.
  --dry-run         Print every git command this would run, then stop.
  --yes             Do not ask for confirmation.
  --no-update-check Skip the check for a newer version of this script. The
                    PLUGIN_ABSORBER_NO_UPDATE_CHECK environment variable does
                    the same.

Merge the resulting pull request with a merge commit. Squash flattens the
imported commits into one and rebase replays them onto your trunk; either
discards what this was for.
USAGE
}

# One short request, and no consequence if it fails: a copy obtained by curl or
# by clone sits outside the plugin update mechanism entirely, and this is all it
# gets to learn that it is stale.
check_for_update() {
	local published

	[ "$update_check" -eq 1 ] || return 0
	[ -z "${PLUGIN_ABSORBER_NO_UPDATE_CHECK:-}" ] || return 0
	command -v curl >/dev/null 2>&1 || return 0

	published=$(
		curl -fsSL --max-time 3 "$PLUGIN_JSON_URL" 2>/dev/null \
			| sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
			| head -n 1
	) || return 0

	[ -n "$published" ] || return 0
	[ "$published" != "$VERSION" ] || return 0

	note "This is absorb-history ${VERSION}; the published version is ${published}."
	note 'Pull your checkout of stellarwp/plugin-absorber, or run /plugin update in Claude Code.'
	note ''
}

rollback() {
	local status=$?

	trap - EXIT
	[ "$rollback_armed" -eq 1 ] || exit "$status"

	note ''
	note 'Undoing everything this run created.'

	# A merge that stopped holds the index and the working tree, so nothing can
	# change branches until it is abandoned. This has to come first.
	if [ -e "$(git rev-parse --git-dir)/MERGE_HEAD" ]; then
		git merge --abort >/dev/null 2>&1 || true
	fi

	[ -z "$created_worktree" ] || git worktree remove --force "$created_worktree" >/dev/null 2>&1 || true
	[ -z "$created_import_branch" ] || git branch -D "$created_import_branch" >/dev/null 2>&1 || true
	[ -z "$original_branch" ] || git switch --quiet "$original_branch" >/dev/null 2>&1 || true
	[ -z "$created_host_branch" ] || git branch -D "$created_host_branch" >/dev/null 2>&1 || true
	[ -z "$created_remote" ] || git remote remove "$created_remote" >/dev/null 2>&1 || true
	[ -z "$scratch_parent" ] || rm -rf "$scratch_parent" || true

	# Report what is true rather than what was attempted. A rollback that claims
	# success over a repository it could not restore sends the next run at a
	# tree nobody has looked at.
	if [ "$(git rev-parse --abbrev-ref HEAD 2>/dev/null)" = "$original_branch" ] \
		&& [ -z "$(git status --porcelain 2>/dev/null)" ]; then
		note "Undone. You are back on ${original_branch} with a clean tree."
	else
		note 'Could not undo all of it. Check "git status" before running this again.'
	fi

	exit "$status"
}

parse_args() {
	local key

	while [ $# -gt 0 ]; do
		key=$1
		case $key in
			import|sync)
				[ -z "$command_name" ] || die "Give one command, not both '${command_name}' and '${key}'."
				command_name=$key
				;;
			--repo)
				repo_url=${2:-}
				shift
				;;
			--into)
				into=${2:-}
				shift
				;;
			--ref)
				ref=${2:-}
				shift
				;;
			--branch)
				host_branch=${2:-}
				shift
				;;
			--remote)
				remote_name=${2:-}
				shift
				;;
			--name)
				display_name=${2:-}
				shift
				;;
			--dry-run)
				dry_run=1
				;;
			--yes|-y)
				assume_yes=1
				;;
			--no-update-check)
				update_check=0
				;;
			--version)
				say "absorb-history ${VERSION}"
				exit 0
				;;
			--help|-h)
				usage
				exit 0
				;;
			*)
				die "Unrecognised argument '${key}'. Run --help for the options."
				;;
		esac
		shift
	done

	[ -n "$command_name" ] || die 'Give a command: import or sync. Run --help for the options.'
	[ -n "$into" ] || die 'Give --into: where the standalone lands in this repository.'

	case $into in
		/*)
			die "--into is relative to the repository root, so it cannot start with '/'."
			;;
		*..*)
			die "--into cannot contain '..'."
			;;
	esac

	into=${into%/}

	[ -n "$host_branch" ] || host_branch="absorb-$(basename "$into")"
	[ -n "$remote_name" ] || remote_name="absorb-$(basename "$into")"
	[ -n "$display_name" ] || display_name=$(basename "$into")

	import_branch="${host_branch}-nested"
}

# git switch arrived in 2.23. Everything else here is older than that.
require_git_version() {
	local have

	command -v git >/dev/null 2>&1 || die 'git is not on PATH.'

	have=$(git --version 2>/dev/null | sed -n 's/^git version \([0-9][0-9.]*\).*/\1/p')
	[ -n "$have" ] || die 'Could not read the git version.'

	awk -v have="$have" 'BEGIN {
		split(have, h, ".")
		major = h[1] + 0
		minor = (h[2] == "" ? 0 : h[2] + 0)
		exit (major > 2 || (major == 2 && minor >= 23)) ? 0 : 1
	}' || die "git 2.23 or newer is required, and this is ${have}."
}

require_host_repository() {
	local top

	git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die 'This is not a git repository.'

	# --into is relative to the repository root, so refuse rather than silently
	# reinterpret it. Both sides are resolved with pwd -P because show-toplevel
	# follows symlinks and $PWD does not, and a symlinked checkout would
	# otherwise be turned away for nothing.
	top=$(git rev-parse --show-toplevel)
	[ "$(pwd -P)" = "$(cd "$top" && pwd -P)" ] \
		|| die "Run this from the repository root, which is ${top}."

	git rev-parse --verify --quiet HEAD >/dev/null || die 'This repository has no commits yet.'

	original_branch=$(git symbolic-ref --quiet --short HEAD) \
		|| die 'HEAD is detached. Check out the branch you want to absorb into first.'

	[ -z "$(git status --porcelain)" ] \
		|| die 'The working tree has changes. Commit or stash them first.'
}

path_is_tracked() {
	git cat-file -e "HEAD:$1" 2>/dev/null
}

branch_exists() {
	git show-ref --verify --quiet "refs/heads/$1"
}

# Adopts an existing remote when it already points at --repo, so a second
# absorption in the same repository is not blocked by the first one's remote.
require_usable_remote() {
	local existing

	if ! existing=$(git remote get-url "$remote_name" 2>/dev/null); then
		[ -n "$repo_url" ] || die "No remote named '${remote_name}', so --repo is required."
		return 0
	fi

	if [ -n "$repo_url" ] && [ "$existing" != "$repo_url" ]; then
		die "The remote '${remote_name}' already points at ${existing}. Pass --remote with another name."
	fi

	repo_url=$existing
}

resolve_ref() {
	local resolved

	if [ -n "$ref" ]; then
		git ls-remote --exit-code "$repo_url" "$ref" >/dev/null 2>&1 \
			|| die "The ref '${ref}' does not exist in ${repo_url}."
		return 0
	fi

	resolved=$(
		git ls-remote --symref "$repo_url" HEAD 2>/dev/null \
			| sed -n 's#^ref: refs/heads/\([^	]*\)	HEAD$#\1#p' \
			| head -n 1
	) || true

	[ -n "$resolved" ] || die "Could not read the default branch of ${repo_url}. Pass --ref."

	ref=$resolved
}

preflight_import() {
	require_git_version
	require_host_repository

	[ -n "$repo_url" ] || die 'Give --repo: the standalone repository to import from.'

	# Re-running the first import is the one mistake with no visible symptom. A
	# second nested branch shares history with the first, so git picks a merge
	# base in which the standalone's files were still at its own root and reads
	# the new nesting as a rename away from it -- deleting a file the host keeps
	# at its own root under one of the same names, README.md most often, and
	# reporting no conflict.
	if path_is_tracked "$into"; then
		die "${into} is already in this branch. Use 'sync' for later commits; never import twice."
	fi

	[ ! -e "$into" ] || die "${into} already exists on disk. Move it aside first."

	! branch_exists "$host_branch" || die "The branch '${host_branch}' already exists. Pass --branch."
	! branch_exists "$import_branch" || die "The branch '${import_branch}' already exists. Pass --branch."

	require_usable_remote
	resolve_ref
}

preflight_sync() {
	require_git_version
	require_host_repository

	path_is_tracked "$into" \
		|| die "${into} is not in this branch. Run 'import' first."

	require_usable_remote
	resolve_ref
}

print_import_plan() {
	say "Import ${display_name} from ${repo_url} (${ref}) into ${into}/"
	say ''
	say "  git switch -c ${host_branch}"
	say "  git remote add ${remote_name} ${repo_url}          # when absent"
	say "  git fetch --no-tags ${remote_name}"
	say "  git worktree add -b ${import_branch} <scratch> ${remote_name}/${ref}"
	say '  # in the scratch worktree, one commit that only renames:'
	say "  #   every top-level entry -> ${STAGING_DIR}/, then ${STAGING_DIR} -> ${into}"
	say "  git merge --allow-unrelated-histories ${import_branch}"
	say '  git worktree remove <scratch>'
	say "  git branch -d ${import_branch}"
	say ''
	say "The remote '${remote_name}' is kept, because 'sync' needs it."
}

print_sync_plan() {
	say "Merge ${display_name}'s later commits from ${remote_name}/${ref} into ${into}/"
	say ''
	say "  git fetch --no-tags ${remote_name}"
	say "  git merge -X subtree=${into} ${remote_name}/${ref}"
}

confirm() {
	local answer

	[ "$assume_yes" -eq 0 ] || return 0

	[ -t 0 ] || die 'Nothing to read a confirmation from. Pass --yes to run without asking.'

	printf 'Proceed? [y/N] ' >&2
	read -r answer || answer=''

	case $answer in
		y|Y|yes|YES) return 0 ;;
		*) die 'Stopped. Nothing was changed.' ;;
	esac
}

add_remote_if_absent() {
	if git remote get-url "$remote_name" >/dev/null 2>&1; then
		return 0
	fi

	git remote add "$remote_name" "$repo_url"
	created_remote=$remote_name
}

# Sets the standalone's whole tree aside and renames it into place as one
# commit. git mv alone leaves every blob identical, so rename detection ties
# each file to its past; rewriting a file here would drop it below the
# similarity threshold and take its history with it.
nest_standalone_tree() {
	local worktree=$1
	local entry

	if git -C "$worktree" ls-tree --name-only HEAD | grep -Fxq "$STAGING_DIR"; then
		die "${display_name} tracks a top-level '${STAGING_DIR}', which this needs for staging."
	fi

	mkdir "$worktree/$STAGING_DIR"

	# A null-delimited read loop rather than xargs: -0 alongside -I is not
	# portable between GNU and BSD xargs, and this also survives a path that
	# starts with a hyphen. git ls-tree lists only top-level entries, which is
	# what has to move -- a hand-written list of paths would miss the
	# standalone's README.md, LICENSE and .gitignore, and those merge into the
	# host's root and conflict with the files of the same name.
	while IFS= read -r -d '' entry; do
		git -C "$worktree" mv -- "$entry" "$STAGING_DIR/"
	done < <( git -C "$worktree" ls-tree --name-only -z HEAD )

	mkdir -p "$worktree/$(dirname "$into")"
	git -C "$worktree" mv "$STAGING_DIR" "$into"

	# --no-verify because the host's commit hooks have nothing useful to say
	# about a pure rename of somebody else's code, and they run in this worktree
	# since it shares the host's .git.
	git -C "$worktree" commit --no-verify --quiet -m "Move ${display_name} under ${into}"
}

do_import() {
	local worktree

	scratch_parent=$(mktemp -d)
	worktree="$scratch_parent/import"

	rollback_armed=1

	git switch --quiet -c "$host_branch"
	created_host_branch=$host_branch

	add_remote_if_absent

	# --no-tags: a plain fetch also takes any tag reachable from what it
	# downloads, so the standalone's 1.1.0 lands among the host's own releases,
	# while a 1.0.0 the host already has silently does not import at all.
	git fetch --no-tags "$remote_name"

	# The destination is not the whole question: importing the same standalone a
	# second time is the mistake, wherever it lands. Once the two share a merge
	# base, git picks one in which the standalone's files were still at its own
	# root and reads a fresh nesting as a rename away from it. The check needs
	# the fetched refs, so it sits here rather than in preflight -- the fetch
	# above is the only thing it leaves to undo.
	if git merge-base HEAD "$remote_name/$ref" >/dev/null 2>&1; then
		die "${display_name}'s history is already in this branch. Use 'sync' for later commits."
	fi

	# The branch work happens in a second checkout of this repository, so the
	# host's own is only ever merged into. Checking the standalone's tree out
	# over the host's would have git write a tree that knows nothing about it,
	# and git does not protect ignored files: a dist/ or assets/ the standalone
	# also tracks takes the host's untracked build output with it, silently.
	git worktree add --quiet -b "$import_branch" "$worktree" "$remote_name/$ref"
	created_worktree=$worktree
	created_import_branch=$import_branch

	nest_standalone_tree "$worktree"

	# Nothing overlaps now, so this merge has nothing to conflict over.
	git merge --allow-unrelated-histories --no-edit \
		-m "Merge ${display_name}'s history under ${into}" "$import_branch"

	# The history is in this branch now. Past this point there is nothing to
	# undo, so the trap stands down and any later failure is reported as it is.
	rollback_armed=0

	git worktree remove "$worktree"
	created_worktree=''
	rm -rf "$scratch_parent"
	scratch_parent=''

	# -D rather than -d: the move commit is in HEAD by way of the merge above,
	# but -d asks whether the branch is merged into its upstream, which is the
	# standalone's own branch, where the move will never appear.
	git branch --quiet -D "$import_branch"
	created_import_branch=''

	say ''
	say "${display_name} is under ${into}/ on ${host_branch}, with its history."
	say ''
	say 'Next:'
	say '  - Host-only edits belong in commits after this one, never folded into the move.'
	say '  - Merge the pull request with a merge commit. Squash and rebase both discard the import.'
	say "  - Later batches of the standalone's commits: absorb-history.sh sync --into ${into}"
	say "  - Once the standalone is archived: git remote remove ${remote_name}"
}

do_sync() {
	add_remote_if_absent

	git fetch --no-tags "$remote_name"

	# -X subtree tells git where the standalone's root now lives, so host-only
	# edits under the prefix survive and new files arrive nested.
	if git merge -X subtree="$into" --no-edit "$remote_name/$ref"; then
		say ''
		say "${display_name}'s later commits are merged under ${into}/."
		return 0
	fi

	# A conflict here is genuine -- both sides edited the same path, or one
	# edited what the other deleted, or both added it -- rather than a sign the
	# prefix is wrong. Leave it in the tree to be resolved by its type.
	note ''
	note 'The merge stopped on conflicts. These paths are unmerged:'
	note ''
	git --no-pager diff --name-only --diff-filter=U | sed 's/^/  /' >&2
	note ''
	note "Resolve each by its type, then: git commit"
	note 'Or abandon the batch with: git merge --abort'

	exit 1
}

main() {
	parse_args "$@"
	check_for_update

	case $command_name in
		import)
			preflight_import
			print_import_plan
			[ "$dry_run" -eq 0 ] || exit 0
			say ''
			confirm
			trap rollback EXIT
			trap 'exit 130' INT TERM
			do_import
			;;
		sync)
			preflight_sync
			print_sync_plan
			[ "$dry_run" -eq 0 ] || exit 0
			say ''
			confirm
			do_sync
			;;
	esac
}

main "$@"
