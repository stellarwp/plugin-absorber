---
name: absorb-history
description: Bring an absorbed standalone WordPress plugin's git history into the host plugin's repository, and merge later batches of the standalone's commits. Use when a plugin is being bundled as a sub-plugin and its commit messages, blame and authorship have to survive the move.
when_to_use: Trigger on "bring the git history over", "absorb <plugin> with its history", "import the standalone's commits", "merge the old repo into this one", or "keep blame when we bundle this plugin".
argument-hint: [standalone-repo-url] [destination-directory]
allowed-tools: Bash(git status:*), Bash(git rev-parse:*), Bash(git log:*), Bash(git diff:*), Bash(git branch:*), Bash(git remote:*), Bash(git ls-files:*)
---

`$CLAUDE_PLUGIN_ROOT/bin/absorb-history.sh` owns the procedure. Run it. Never
reimplement a step of it, and never substitute your own git commands for it.

## Collect

- `--repo` — the standalone's repository URL.
- `--into` — where its tree lands, relative to the host repository root, for
  example `sub-plugins/give-recurring`.
- `--branch` — defaults to `absorb-<basename of --into>`. Offer that default;
  take a more meaningful name if the user has one.

## Run

1. Confirm the working directory is the host plugin's repository root and
   `git status` is clean.
2. Run with `--dry-run`. Show the user the commands it prints.
3. On approval, run the same command with `--yes`.
4. Report what the script printed, including its `Next:` list in full.

Later batches of the standalone's commits use `sync`, not `import`. The script
refuses a second `import`; that refusal is correct, so never work around it.

## When `sync` stops on conflicts

The script leaves the merge in the working tree and exits non-zero. The conflict
is genuine — both sides edited the same path, or one edited what the other
deleted, or both added it. Read the unmerged paths, resolve each by its type,
then `git commit`. `git merge --abort` abandons the batch.

## Close by saying

The pull request has to be merged with a merge commit. Squash flattens the
imported commits into one and rebase replays them onto the trunk; either
discards what this was for.
