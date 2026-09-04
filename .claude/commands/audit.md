---
description: Run the full read-only platform audit (security, clean code, DB, reachability, conflicts, booking flow)
argument-hint: [phase: recon|security|db|frontend|booking|report|all]
---

Run phase "$1" of the platform audit defined in AUDIT-PROMPT.md at the repo root.
Read that file first and follow its rules exactly.

Hard rules for this session:
- READ-ONLY. Do not modify, refactor or "fix while passing by". Findings only.
- Every finding cites file:line with the proving snippet, or is dropped.
- Write outputs under `audit/`.
- After the phase completes, print a 10-line summary and STOP. Wait for approval.

Phase map:
- recon    -> produce audit/00-MAP.md (versions, tree, full route inventory, entities)
- security -> security-auditor + booking-flow-verifier
- db       -> db-performance-auditor
- frontend -> reachability-auditor + conflict-detector
- booking  -> booking-flow-verifier only
- report   -> consolidate all audit/*.md into audit/REPORT.md
- all      -> run every phase in the order above, stopping after each for approval
