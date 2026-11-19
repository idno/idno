This is Idno's data directory.

It is where the database of an SQLite site lives (for example data/idno.sqlite, with the
idno.sqlite-wal and idno.sqlite-shm files SQLite keeps beside it while the site runs), and the
lock file the upgrade creates next to it (idno.sqlite.upgrade.lock).

The web server must be able to write to this directory (SQLite creates its journal files here, not
only the database file), and it must be a directory on a local disk: SQLite's write-ahead log does
not work on a network filesystem (NFS, SMB). Idno's web server configurations refuse to serve
anything in this directory, and anything ending in .sqlite, .sqlite-wal, .sqlite-shm or .upgrade.lock
anywhere.

This file exists to ensure that the folder makes it to you. The contents of the directory are not
part of the source and are never committed.
