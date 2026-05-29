# UX Review — Learning Outcomes: Block + Manage Page

## Summary

This change lets teachers link assessable course activities to learning outcomes on a dedicated page, and shows the resulting list to students via a block. Teachers use a per-outcome dropdown and Add button (immediate AJAX) plus a Remove button with a 10-second deferred undo. UX risk: **medium**. The core flow is sensible, but there are two issues that could silently lose or mislead users — one involving data integrity on navigation and one involving page language shown to the wrong audience.

---

## User intent and clarity

**Page title shown to the wrong audience.**
The page is `manage.php`. Students with `report/learningoutcomes:view` see it too, but the heading, page title, and description are entirely teacher-facing:

```php
// manage.php line ~45
$PAGE->set_title(get_string('manage_pagetitle', 'report_learningoutcomes'));
// → "Manage learning outcomes"

// manage.php, page description
'This page shows the learning outcomes … and lets you link them to course activities.'
```

A student who lands here via the block link — if one existed — or via a bookmark would read "Manage learning outcomes" and "lets you link them" and not understand why they can't do anything. This is teacher language on a dual-audience page.

**The "Add or edit outcomes" link is easy to miss.**
It appears in a `small fw-semibold` line above the outcome cards, styled down in size. For teachers who are on this page and don't yet understand why outcomes are missing or stale, it is the critical next action — but it looks like a footnote. In the empty state (`manage_nooutcomes`) the link is more prominent (plain `<p>`), which is the right call there.

**"Link activity:" vs "Add."**
The label says "Link activity:" but the button says "Add" (`get_string('add')` — Moodle core generic). The two words describe the same action. A teacher scanning a dense page could reasonably wonder if "Add" creates a new outcome or adds a new activity. "Link" on both label and button would be unambiguous.

---

## Behaviour and feedback

**Navigating away during the 10-second undo window silently cancels the removal.**
This is the most significant behaviour issue.

When a teacher clicks Remove:
1. The row is hidden immediately.
2. A `setTimeout` of 10 000 ms is set to call `callAjax('untag', ...)`.
3. If the teacher navigates to another page before 10 s elapses, the JavaScript context is destroyed. The `setTimeout` never fires. The database is never updated.
4. On return, the activity reappears as if nothing happened.

The teacher believes they removed the link. It is still there.

```javascript
// manage.js ~line 175
const ajaxTimer = setTimeout(async() => {
    ...
    await callAjax('untag', cmid, outcomeid);
    ...
}, 10000);
// ← if user navigates away now, this never fires
```

The fix is straightforward: call `untag` immediately and make the 10-second window a client-side undo that calls `retag` if the user acts. This is the model Gmail uses for archive/undo. The current pattern is the inverse.

**Add is immediate; Remove is deferred — the model is asymmetric.**
Adding a link gives instant feedback (Moodle notification at the top: `manage_addedmsg`). Removing gives deferred feedback (10 s undo toast, removal is silent). These are the same type of action and users will not intuitively understand why one is instant and the other is not.

**Two hardcoded English strings in JavaScript.**
The undo toast and the empty-table placeholder both use literal English text that bypasses Moodle's lang system entirely:

```javascript
// manage.js: undo toast (not using manage_removedmsg or manage_undo)
alert.innerHTML = `
    <span><strong>${escHtml(activityname)}</strong> removed from outcome.</span>
    <a href="#" class="lo-undo-link alert-link ms-1">Undo</a>
    <span class="text-muted small ms-auto lo-countdown">(10s)</span>
    ...
`;

// manage.js: maybeShowEmptyPlaceholder — not using manage_noactivitieslinked
const msg = 'No activities linked to this outcome yet.';
```

The lang strings `manage_removedmsg`, `manage_undo`, and `manage_noactivitieslinked` are defined in the lang file and already used elsewhere. These two places just don't use them. This affects all non-English users.

**No loading indicator on the Add button.**
The button is disabled (`btn.disabled = true`) while the AJAX call is in flight but there is no spinner or text change. On a slow connection, the button silently greys out and users may click it again or assume the page has frozen.

---

## Edge cases and error states

**Closing the undo toast × button does not cancel the pending removal — but it looks like it might.**
The `btn-close` inside the undo alert closes the alert visually. The AJAX `ajaxTimer` runs independently in the closure and still fires. This is the right data behaviour, but a user who clicks × thinking "never mind, I want it back" will be surprised when the link is gone on next reload. The × button should either be removed or, on click, trigger the undo action.

**Untagged activities panel is a dead end.**
The warning card at the bottom of the page lists activities with no outcome. There is no action available from that list:

```php
// manage.php ~line 265 — untagged activities table, no add controls here
echo html_writer::tag('td', html_writer::link($url, $icon . format_string($cm->get_formatted_name())));
echo html_writer::tag('td', html_writer::tag('span', ucfirst($cm->modname), ...));
// no "Link to outcome" control
```

Teachers must mentally note the activity name, scroll up to the right outcome card, find it in the dropdown, and add it. On a course with many outcomes, this is cumbersome.

**Activity type column shows internal plugin names.**
`ucfirst($cm->modname)` produces "Assign", "Quiz", "Hvp", "Lti", "Scorm". For standard Moodle modules, these are recognisable. For third-party plugins (`hvp`, `lti`) they are opaque to teachers. Moodle's `get_string('pluginname', $cm->modname)` is the conventional localised label.

**The decorative modules list is hardcoded.**
```php
// manager.php
public function get_decorative_modules(): array {
    return ['label', 'page', 'url', 'folder', 'resource', 'book', 'imscp', 'subsection'];
}
```
`page` and `resource` are excluded from the "assessable" bucket. Teachers who use a Page activity as a graded reading, or a File resource that students must submit evidence for, cannot tag those activities. There is no UI explanation for why they are absent from the dropdown — the activity simply does not appear. Teachers will be confused.

**`mt-5` in the block item header.**
In `block_learningoutcomes.php`, each outcome badge header uses class `mb-1 mt-5`:
```php
$header = html_writer::tag('div', $badge, ['class' => 'mb-1 mt-5'])
```
`mt-5` is Bootstrap's `3rem` top margin. In a sidebar block with several outcomes this creates huge visual gaps that break the layout on small screens and look like rendering errors.

---

## Consistency with Moodle

**The undo-then-AJAX pattern is non-standard in Moodle core.**
Core actions (deleting a course, removing an enrolment) use a confirmation dialogue before the action. The deferred-undo model is a reasonable modern pattern but it is not established in Moodle's design system. Its correct implementation matters here because the current implementation has the data-integrity gap described above.

**The report is split across two pages with no cross-linking.**
`index.php` is the gap report (coverage analysis). `manage.php` is the management page. Navigation only registers `index.php` in the course nav:
```php
// lib.php — only index.php is added to navigation
$url = new moodle_url('/report/learningoutcomes/index.php', ['id' => $course->id]);
```
A teacher who reaches `index.php` and sees untagged outcomes has no link to `manage.php`. The path back is via the block footer link (which is only shown if the block is added to the course page). The two pages are effectively siloed.

---

## Accessibility (UX layer)

**The undo toast's role is `role="status"` (live region).**
This means a screen reader will announce the message when it appears. That's the right choice. However, the Undo link is inside the live region. On some screen reader / browser combinations, interactive controls inside live regions are not reliably reachable. Keyboard users should be able to Tab to the Undo link after it appears; screen reader users may not hear it announced as actionable.

**The countdown `(10s)` updates every second.**
Because the undo alert has `role="status"`, each countdown tick (`(9s)`, `(8s)`, etc.) may be announced by the screen reader, flooding the user with irrelevant announcements for 10 seconds. The countdown element should be hidden from assistive technology (`aria-hidden="true"`) since it is visual affordance only.

**The Remove button has no accessible label beyond its text.**
`data-activityname` is available on the button for JS use, but the button text is just "Remove". In a table with several activities across several outcomes, a screen reader user navigating by button would hear a sequence of "Remove, Remove, Remove" with no context. An `aria-label` like "Remove [activity name] from [outcome name]" would resolve this.

---

## Questions a designer would ask

1. **Why is the 10-second window 10 seconds?** Is there evidence this is long enough for a teacher to change their mind? Long enough to be annoying for a teacher who is confidently bulk-cleaning a course?

2. **Why is the undo pattern only on Remove, not on Add?** If a teacher accidentally adds the wrong activity to an outcome, their only recourse is to find the Remove button. Adding an undo to Add would complete the model symmetrically.

3. **Who owns outcome definitions?** The page description says outcomes are managed via "Grades > Outcomes." But site admins can create global outcomes, and course editors can create course-scoped outcomes. A teacher who lands on an empty manage page and clicks through to the gradebook outcomes page sees a very different interface with scale requirements. No preparation is given for that UX transition.

4. **What does a student do with this block?** The block shows outcomes and the activities aligned to them. For students, this could serve as a study guide or a self-regulation tool. But there is no guidance copy in the block ("These are the skills you will develop in this course") — just a list. The `courseoutcomes_intro` string ("By the end of this course, you will be able to:") provides the intro paragraph but the activity links below each outcome may be confusing without explanation of *why* those activities are listed.

5. **What happens when a course has 20+ outcomes?** The page renders all outcomes as stacked Bootstrap cards with no search, filter, or collapse. On a large post-secondary course with a full outcome set this page becomes a very long scroll. No affordance for navigating to a specific outcome by name.

6. **Is the block visible to unenrolled users/guests?** `applicable_formats()` limits to course pages, and `is_enabled_for_course()` checks the config, but there is no explicit `require_login()` in the block. The block silently returns empty content for most edge cases, which is safe, but the intent is unclear.

---

## Recommendations

| Priority | Issue | Why it matters | Suggested change |
|---|---|---|---|
| **High** | Remove AJAX fires after 10 s — navigating away silently drops the operation | Teachers believe they removed a link; it reappears on next load | Call `untag` immediately; implement undo by calling `tag` if the user acts within 10 s |
| **High** | "Manage learning outcomes" heading visible to students | Students see teacher-only language and are confused about their role | Use a different heading (e.g., "Learning outcomes") for users without `manage` capability, or redirect students to a read-only variant |
| **High** | Two hardcoded English strings in JS | Untranslatable for non-English sites | Replace with `getString('manage_removedmsg', ...)`, `getString('manage_undo', ...)`, and `getString('manage_noactivitieslinked', ...)` — the strings are already defined |
| **Medium** | Undo toast × button does not cancel the removal but looks like it might | Users clicking × to cancel are surprised the change persists | Either remove the × button or wire it to the same undo function |
| **Medium** | Countdown ticks are announced by screen readers | Screen reader users hear 10 second-by-second announcements | Add `aria-hidden="true"` to `.lo-countdown` |
| **Medium** | Remove buttons have no context in their accessible name | Screen reader users cannot identify which activity they are acting on | Add `aria-label="Remove [activity name] from [outcome name]"` to each Remove button (data attributes are already present) |
| **Medium** | Untagged activities panel has no link action | Teachers see the problem but cannot fix it from where they see it | Add a "Link to an outcome" dropdown or at minimum a link to jump to that outcome's card |
| **Medium** | `mt-5` in block outcome header | Renders large visual gaps in the sidebar | Change `'class' => 'mb-1 mt-5'` to `'class' => 'mb-1 mt-2'` or `mt-3` |
| **Medium** | No loading indicator on Add button | On a slow connection, users cannot tell the request is in progress | Add a spinner icon inside the button or swap the button text to "Linking…" while disabled |
| **Low** | Activity type uses `ucfirst(modname)` | "Hvp", "Lti", "Scorm" are meaningless to most teachers | Use `get_string('pluginname', $cm->modname)` with a fallback |
| **Low** | "Link activity" label but "Add" button | Inconsistent terminology for the same action | Change the button label to "Link" |
| **Low** | `index.php` and `manage.php` have no cross-links | Teachers who land on the gap report have no path to the manage page | Add a "Manage activity links" button/link to `index.php` |
