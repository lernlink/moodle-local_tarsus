# local_tarsus

Foundation / SDK plugin for the Tarsus ↔ Moodle integration (Lerncoach).

This plugin isolates **all** Tarsus API traffic. It exposes a stable client
interface (mock + real HTTP skeleton + factory), the guardrails pipeline
(kill-switch, circuit breaker, credit-budget gate, rate limiter, capped
backoff), the local indexation index map, pseudonymizer, usage meter, tasks
and the privacy provider. No end-user UI.

- **Component:** `local_tarsus`
- **Target:** Moodle 5.2 · PHP 8.3
- **Status:** mock-first (live Tarsus API pending)

See the project technical specification for full design details.

## License

GNU GPL v3 or later — https://www.gnu.org/licenses/
