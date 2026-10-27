# Metric rules

[Русская версия](ru/metrics.md)

## Crapper

Cyclomatic complexity starts at 1 per function, method, closure, or arrow function. Add 1 for each `if`, `elseif`, `for`, `foreach`, `while`, `do`, `catch`, non-default `case`, non-default `match` arm, ternary, `&&`, `||`, logical `and`/`or`, or `??`. Nested functions and closures receive their own score. Abstract/interface methods have no body and are omitted.

Clover `<line type="stmt">` records inside a unit's inclusive span define executable lines. Coverage is the percentage with hit count above zero; it is line coverage, not branch coverage. Missing reports, unmatched units, and units with no executable lines have unknown coverage. For known coverage percentage `P`, `CRAP = CC² × (1 − P/100)³ + CC`. Unknown coverage produces `crap: null` in JSON and `nil` in EDN. A known CRAP score of 10 or greater fails the default quality gate.

## Dryer

Only ordinary functions and concrete methods with at least 4 source lines and 20 normalized AST nodes are candidates. Names of local variables and literals are ignored; AST shapes, operators, and direct call names are retained. Similarity is Jaccard intersection divided by union of feature sets. The default report threshold is 0.82; candidates are warnings and do not fail CI. `ub-mode` agent reviews may request 0.8 explicitly. Closures and arrows are excluded from duplication comparison in v0.1.

The normalization is heuristic. Review candidate pairs before refactoring. JSON includes IDs and richer fields; `.metrics/crap.edn` follows the [Crapper snapshot](https://github.com/unclebob/crapper), and `.metrics/dry.edn` follows the [Dryer snapshot](https://github.com/unclebob/dryer). For viewer compatibility, EDN only exports fields and names those formats can join.
