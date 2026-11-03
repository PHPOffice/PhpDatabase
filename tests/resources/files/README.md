# Test files

| File | Created with |
|------|--------------|
| `jackcess-2000.mdb`, `jackcess-2007.accdb` | [Jackcess](https://jackcess.sourceforge.io/) 4.0.5 (Access 2000 and 2007 formats) |
| `hsqldb-cached.odb`, `hsqldb-nopk.odb` | HSQLDB 1.8.0.10 (the engine embedded in LibreOffice Base), CACHED and MEMORY tables, `user.timezone=Europe/Paris` for the first one, `UTC` for the second one |
| `sqlite3-features.sqlite` | SQLite 3.37 (UTF-16le encoding, `WITHOUT ROWID` table, overflow pages, view) |
| `hsqldb-log.odb` | HSQLDB 1.8.0.10, killed without checkpoint: changes in `database/log` (committed and uncommitted), views, check constraints, default expressions, a LibreOffice Base query in `content.xml` |
| `jackcess-queries.accdb`, `jackcess-complex.accdb`, `jackcess-extdate.accdb`, `jackcess-calculated.accdb` | Microsoft Access, test files of Jackcess (`queryTestV2007`, `complexDataTestV2010`, `extDateTestV2019`, `calcFieldTestV2010`): queries, attachments and multi-valued fields, extended date/time with ascending and descending indexes, calculated columns |
