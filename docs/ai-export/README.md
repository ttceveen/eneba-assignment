# Local AI interaction export

This directory preserves all project-relevant Codex session files discovered in the local sessions store for Eneba Internship Assignment, starting on 9 November 2026. It is an export of available local records, not a claim to contain every AI interaction on every service.

## Sources and scope

Source: `C:/Users/ttcev/.codex/sessions/`. Every JSONL file in that store was examined by creation date, working-directory metadata and project references in its contents, including sessions opened outside the project directory. The manifest lists included and excluded sessions. No Git files or browser internals were accessed.

Each `codex/session-NNN.jsonl` contains a complete source session as recorded at the snapshot boundary in `manifest.json`. Sessions are kept separate. Original event order, event structure, message languages, user messages, Codex replies, tool calls, command results, errors, corrections, retries, intermediate actions, final reports and service events are preserved. Nothing is selected based on whether it was successful or useful. Existing summaries/compaction records remain as originally stored; missing material cannot be reconstructed from them. Attachments or external resources referred to by a session are not embedded unless already present in the original event.

The currently running session is necessarily a point-in-time snapshot. Export/verification actions and the final response occurring after that boundary are not included. A later export would be required to capture those events. Date bounds are event timestamps in UTC; a filename's local calendar date can differ.

## Confidential information

Secrets detected in exported string values are replaced with `[REDACTED]`. Only the detected value is replaced; its surrounding message/event is retained. Detection uses local environment secret literals, sensitive assignments/fields, credential-bearing URLs, authorization values and recognizable key/token formats. Some synthetic test credentials are also redacted conservatively. Redaction counts are occurrences across records, not a count of distinct credentials. Original session files are never modified.

Redaction necessarily changes affected string contents and may reserialize affected JSON lines. Unaffected lines are retained verbatim. Event counts and recursive JSON structure are checked against each source snapshot. Known environment secret literals are checked for absence after export. Pattern-based scanning cannot prove that every unlabelled, encoded or previously unknown credential has been identified; review before public distribution is appropriate.

## Reading and verification

JSONL has one JSON object per line. Read files with a JSONL viewer or parse each line independently; do not treat the whole file as one JSON array. `manifest.json` supplies the original session ID, source path, first/last event dates, export path, event counts, replacement counts, SHA-256 and byte size for each file. Checksums apply to the exported files, including their final newline. Event types and nested payloads follow the original Codex recording format; duplicated records already present in the source are intentionally retained.

## Unavailable history

No conventional ChatGPT exports (`conversations.json`, `chat.html`, or ChatGPT/OpenAI/export-named ZIP files) were found in the user's Desktop, Documents and Downloads trees, excluding dependency/build directories. The `chatgpt/` directory is intentionally empty. ChatGPT cloud conversations and exports stored elsewhere are unavailable to this export and must be supplied separately by the user. No conversations have been recreated from memory. Existing project AI notes and historical prompts were left unchanged and are not substitutes for original session records.

## Snapshot results

- Codex sessions: 13.
- Events: 18054.
- Secret replacements: 1129.
- UTC event range: 2026-11-10T15:39:45.020Z to 2026-11-16T00:36:42.726Z.
- Invalid JSON source records: 0.
- All discovered relevant local Codex session snapshots included: yes.
