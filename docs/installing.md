# Installing

```bash
composer require stellarwp/plugin-absorber
```

Requires PHP 7.4+ and WordPress 6.4+. The WordPress floor comes from the `wp_admin_notice_markup`
filter; WordPress is not a Composer dependency, so nothing enforces it at install time.

## Strauss

Prefix this library with [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md).
Two plugins shipping different versions of it will collide otherwise.

> **Nothing may rewrite a sub-plugin's `plugin_loaded_constant`.** The bundled copy and the
> standalone must define the *same* name, or the guard matches nothing. This library only ever reads
> such a name out of your config, so its own source is safe to prefix in full — but if your build
> also runs the bundled plugin's files through Strauss, keep `extra.strauss.constant_prefix` away
> from them.

## Bringing the standalone's git history with it

Copying the standalone's files into your repository loses its git history. The commit messages, the
blame, and with them the reason any given line is written the way it is, all stay behind in the old
repository — so a developer or an agent working on the bundled copy later has nothing to go on, and
`git blame` answers every question with the single commit that copied the files in.

A submodule would keep that history, but it is the wrong shape here: a bundled copy usually needs
small host-only edits that have no business in the standalone's repository, which is normally
archived soon afterwards anyway.

Merge the standalone in as an unrelated history instead, nesting its whole tree under the sub-plugin
directory first so that the two share no path and the merge has nothing to conflict over:

```bash
# On a branch of the host plugin's repo.
git switch -c absorb-give-recurring

# Pull the standalone's commits in as a second, unrelated history.
git remote add old-repo git@github.com:givewp/give-recurring.git
git fetch --no-tags old-repo

# Nest every top-level entry it tracks, so it shares no path with the host.
git switch -c old-repo-import old-repo/main
mkdir __absorb-import
git ls-tree --name-only -z HEAD | xargs -0 -I{} git mv {} __absorb-import/
mkdir -p sub-plugins
git mv __absorb-import sub-plugins/give-recurring
git commit -m "Move Give Recurring under sub-plugins/give-recurring"

# Nothing overlaps now, so this merge has nothing to conflict over.
git switch absorb-give-recurring
git merge --allow-unrelated-histories old-repo-import

# The history is part of your branch now; the remote and import branch can be deleted.
git remote remove old-repo
git branch -d old-repo-import
```

**Move everything, so the merge only ever touches the sub-plugin directory.** The standalone's
`README.md`, `LICENSE` and `.gitignore` sit at *its* top level: leave them there and they merge into
*your* repository root, conflicting with the files of the same name and adding the ones with no
counterpart. `git ls-tree` moves whatever the standalone tracks, which a hand-written list of paths
will not.

**The staging directory keeps the destination out of the tree being moved.** That move runs against
the standalone's own checkout, so a destination like `includes/notifications` lands under an
`includes/` the standalone tracks itself, and git will not move a directory into itself. Only that
one entry fails while every other one succeeds, so the error scrolls past in a screen of moves that
worked. Setting the whole tree aside under a name nothing uses, then renaming it into place once,
does not care where the destination sits.

**`--no-tags`.** A plain fetch also takes any tag reachable from what it downloads. The standalone's
`1.1.0` then sits among your own releases, while a `1.0.0` you already have silently does not import
at all, since git will not move an existing tag.

**The move commit moves and nothing else.** `git mv` alone leaves every blob identical, so rename
detection ties each file to its past. Rewrite a file in that same commit and it drops below the
similarity threshold, taking its history with it — blame stops at the move. Host-only edits belong in
commits after the merge.

**Merge the PR with a merge commit.** Squash flattens the imported commits into one and rebase
replays them onto your trunk; either discards what this was for.

Afterwards `git blame` and `git log --follow` cross the move with no extra flags, attributing each
line to the author who wrote it in the standalone. Path-filtered
`git log -- sub-plugins/give-recurring` is the exception and stops at the move, since that is where
the directory begins.

Where the files were copied in by hand already, that merge conflicts `add/add` on every shared file
and `-X ours` resolves toward what you have, leaving the tree byte-identical and blame intact. It
only settles files present on both sides, so read `git show --stat HEAD` for anything the standalone
tracked and you never copied.
