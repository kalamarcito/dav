# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

A fork of **sabre/dav** (`kalamarcito/dav`), maintained to add **group-based CalDAV/CardDAV
sharing** used by [Davis](https://github.com/kalamarcito/davis). Upstream: `sabre-io/dav`
(remote `upstream`). Working branch: **`feat/carddav-sharing`**.

Davis consumes this fork via a `composer.json` VCS repository, pinned by commit **reference**
in Davis's `composer.lock`. Bump the pin there after changing this fork.

## Fork-specific behavior (feat/carddav-sharing)

- **Group discovery:** `getCalendarsForUser` / `getAddressBooksForUser` also return instances
  whose `principaluri` is a **group** the user belongs to — via a JOIN on `groupmembers`,
  filtered to `principals/group-%` (so calendar-proxy delegation URIs are not treated as
  share groups).
- **Permission union:** when a resource is shared to the user **and** to a group they belong
  to, the effective `permissions` bits are OR-ed (most permissive wins).
- **Readonly integrity:** pure readonly shares must not advertise `{DAV:}write-properties`
  on `SharedCalendar` / `SharedAddressBook`, otherwise clients (e.g. Thunderbird) show
  edit/delete UI even though `unbind` / `write-content` are denied.
- Group principal URIs are **flat** (`principals/group-<slug>`); nested paths break the
  principal collection tree.

## Conventions

- Keep changes minimal and rebased on `upstream/main` where possible; this is a fork meant to
  track upstream, not diverge widely.
- Match sabre's existing code style (PSR-12-ish, typed signatures where upstream uses them).
