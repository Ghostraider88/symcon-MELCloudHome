# Tests

Das Repository nutzt die offiziellen Hilfs-Repositories von Symcon als **Git-Submodule**.
Nach dem Klonen müssen sie nur initialisiert werden:

```bash
git submodule update --init --recursive
```

`tests/stubs` stellt unter anderem `IPSModuleStrict`, die Kernel-/I/O-Stubs und den
offiziellen `validateLibrary()`-/`validateModule()`-Validator bereit. Die Beispiele in
[SymconTest](https://github.com/symcon/SymconTest) dienen als Referenz für weitergehende
Lebenszyklus-, Datenfluss-, Aktions- und Presentationstests.

Lokal ausführen:

```bash
vendor/bin/phpunit
```

Die GitHub-Actions-Workflows (`.github/workflows/tests.yml`, `style.yml`) führen die
Tests und die Stilprüfung automatisch bei jedem Push und Pull Request aus.
