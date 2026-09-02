# Bringing the standalone's git history with it

Copying the standalone's files into your repository loses its git history. The commit messages, the
blame, and with them the reason any given line is written the way it is, all stay behind in the old
repository — so a developer or an agent working on the bundled copy later has nothing to go on, and
`git blame` answers every question with the single commit that copied the files in.

A submodule would keep that history, but it is the wrong shape here: a bundled copy usually needs
small host-only edits that have no business in the standalone's repository, which is normally
archived soon afterwards anyway.

Merge the standalone in as an unrelated history instead, nesting its whole tree under the sub-plugin
directory first so that the two share no path and the merge has nothing to conflict over. That is
what `bin/absorb-history.sh` does.

## Running it

The script is in this repository, not in the Composer package — it is a development tool, so it is
`export-ignore`d and never reaches your `vendor/`. Clone this repository and run it from the root of
the host plugin's:

```bash
git clone https://github.com/stellarwp/plugin-absorber.git ~/src/plugin-absorber

cd ~/src/give
~/src/plugin-absorber/bin/absorb-history.sh import \
    --repo git@github.com:givewp/give-recurring.git \
    --into sub-plugins/give-recurring
```

It creates the branch, nests the standalone's tree, merges it in, and cleans up after itself. Pass
`--dry-run` to see every git command it will run without running any of them, and `--help` for the
options — `--branch` for a branch name of your own, `--ref` for a tag or a branch other than the
standalone's default.

Nothing mutates until every check passes: the working tree has to be clean, the destination must not
already exist, and the branch and remote names must be free. If a step fails after that, the script
undoes what it created and tells you which branch you are on.

## Later commits to the standalone

The standalone may get more commits before it is archived. `import` keeps the remote it added for
exactly this, and `sync` merges each new batch with `-X subtree`, which tells git where the
standalone's root now lives:

```bash
~/src/plugin-absorber/bin/absorb-history.sh sync --into sub-plugins/give-recurring
```

Host-only edits under the prefix survive, new files arrive nested, and blame still names the author
who wrote each line in the standalone. Repeat it for each batch, then remove the remote once the
standalone is archived — `import` prints the exact command, and the remote is named after the
destination unless you passed `--remote`:

```bash
git remote remove absorb-give-recurring
```

A conflict here is genuine — both sides edited the same path, or one edited what the other deleted,
or both added it — rather than a sign the prefix is wrong. The script leaves the merge in your tree
and lists the unmerged paths; resolve each by its type and commit, or `git merge --abort` to abandon
the batch.

## Optionally, through Claude Code

This repository is also a Claude Code plugin marketplace, so the script can be driven by a skill
instead of typed. The skill is an interface to the same script and does nothing the script does not:

```bash
/plugin marketplace add stellarwp/plugin-absorber
/plugin install plugin-absorber@nexcess-plugin-absorber
/plugin-absorber:absorb-history
```

Auto-update is off by default for third-party marketplaces, so turn it on once in **/plugin →
Marketplaces** if you want later versions. Your team can register the marketplace for everyone by
adding it to the host plugin's own `.claude/settings.json`:

```json
{
    "extraKnownMarketplaces": {
        "nexcess-plugin-absorber": {
            "source": { "source": "github", "repo": "stellarwp/plugin-absorber" }
        }
    }
}
```

That registers the marketplace once the repository folder is trusted. Each developer still runs the
install command themselves, which Claude Code prints for them.

## Why it works the way it does

**Move everything, so the merge only ever touches the sub-plugin directory.** The standalone's
`README.md`, `LICENSE` and `.gitignore` sit at *its* top level: leave them there and they merge into
*your* repository root, conflicting with the files of the same name and adding the ones with no
counterpart. The script moves whatever the standalone tracks, which a hand-written list of paths
will not.

**A staging directory keeps the destination out of the tree being moved.** That move runs against the
standalone's own checkout, so a destination like `includes/notifications` lands under an `includes/`
the standalone tracks itself, and git will not move a directory into itself. Only that one entry
fails while every other one succeeds, so the error scrolls past in a screen of moves that worked.
Setting the whole tree aside under a name nothing uses, then renaming it into place once, does not
care where the destination sits.

**A scratch worktree is what stops this deleting your build output.** Checking the standalone's tree
out over your own means git writing a tree that knows nothing about your repository, and git does not
protect ignored files: one that collides is overwritten in place, and switching back then removes any
directory left holding nothing but ignored files. A `dist/` or `assets/` the standalone also tracks
takes your untracked build artifacts with it, silently, and a `.gitignore`d database dump under one
goes the same way. A worktree is a second checkout of the same repository in another directory, so
the branch work happens there and yours is only ever merged into.

**`--no-tags` on every fetch.** A plain fetch also takes any tag reachable from what it downloads. The
standalone's `1.1.0` then sits among your own releases, while a `1.0.0` you already have silently does
not import at all, since git will not move an existing tag.

**The move commit moves and nothing else.** `git mv` alone leaves every blob identical, so rename
detection ties each file to its past. Rewrite a file in that same commit and it drops below the
similarity threshold, taking its history with it — blame stops at the move. Host-only edits belong in
commits after the merge.

**Merge the pull request with a merge commit.** Squash flattens the imported commits into one and
rebase replays them onto your trunk; either discards what this was for.

**Never import the same standalone twice.** A second nested branch shares history with the first, so
git picks a merge base in which the standalone's files were still at its own root, and reads the new
nesting as a rename away from that root. A file you keep at *your* root under one of the same names —
`README.md`, most often — is deleted as part of that rename, and the merge reports no conflict. The
script refuses a second `import` whatever destination you give it, and points you at `sync`.

Afterwards `git blame` and `git log --follow` cross the move with no extra flags, attributing each
line to the author who wrote it in the standalone. Path-filtered
`git log -- sub-plugins/give-recurring` is the exception and stops at the move, since that is where
the directory begins.
