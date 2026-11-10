# Changes

## 1.16.0 (2026-11-10)

- Table questions are read as the brain sends them: any number of columns under their own names (client contract change: rows are `{cells: [{value, ask, verdict}]}`).
- Agent-driven learning steps the brain now wraps in a stack are shown as interactive web cards again instead of one-slide text cards.

## 1.15.0 (2026-11-06)

- First stable release prepared for the Moodle plugins directory.
- Interaction type (AI manager-driven, agent-driven) passed to the brain; interactive web cards in chat, learning units and the feed.
- Coding style aligned with moodle-cs; CI matrix covers Moodle 5.2 and 5.3.
