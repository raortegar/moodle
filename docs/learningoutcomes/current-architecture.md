# Outcomes Subsystem — Current Architecture (Moodle 5.2)

> Phase 0 — Discovery output.  
> Goal: understand exactly what exists before touching anything.

---

## 1. Database Tables

### Primary tables

| Table | Purpose |
|---|---|
| `grade_outcomes` | Stores outcome definitions (site-wide and per-course) |
| `grade_outcomes_courses` | Junction: links an outcome to a course (many-to-many) |
| `grade_outcomes_history` | Audit log of changes to outcome records |

### Related tables (not outcome-specific but tightly coupled)

| Table | Coupling point |
|---|---|
| `grade_items` | Has `outcomeid` FK — when set, the grade item *is* the outcome grading row |
| `scale` | `grade_outcomes.scaleid` — currently **required** (hard-coupled, see §5) |
| `grade_grades` | Stores per-user scores for outcome grade items (same as regular items) |
| `grade_categories` | Has `aggregateoutcomes` flag — controls whether outcome items feed into totals |

### Field-level detail

**`grade_outcomes`**

| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `courseid` | int | NULL = site/global outcome; non-null = course-scoped outcome |
| `shortname` | char | Used in gradebook UI and reports |
| `fullname` | text | Display name |
| `scaleid` | int | FK to `scale`. Currently **mandatory** in the edit form (see §5) |
| `description` | text | Free-text description |
| `descriptionformat` | int | Format constant for description |
| `timecreated` | int | Unix timestamp |
| `timemodified` | int | Unix timestamp |
| `usermodified` | int | FK to `user` |

**`grade_outcomes_courses`**

| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `courseid` | int | FK to `course` |
| `outcomeid` | int | FK to `grade_outcomes` |

> An outcome can exist globally (`courseid = NULL`) and still be used in many courses via this table.

**`grade_outcomes_history`**

| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `oldid` | int | ID of the original `grade_outcomes` record |
| `timemodified` | int | When the change happened |
| `loggeduser` | int | Who made the change |
| `courseid` | int | Snapshot of courseid at time of change |
| `shortname` | char | Snapshot |
| `fullname` | text | Snapshot |
| `scaleid` | int | Snapshot |
| `description` | text | Snapshot |
| `descriptionformat` | int | Snapshot |

**`grade_items` — outcome-relevant fields**

| Field | Notes |
|---|---|
| `outcomeid` | When non-null, this grade item represents an outcome grading row |
| `scaleid` | Copied from the linked outcome's scale |
| `itemnumber` | Outcome items get `itemnumber > 0` on the same activity |
| `gradetype` | Always `GRADE_TYPE_SCALE` for outcome items |

---

## 2. Entity-Relationship Diagram

```
grade_outcomes
  id ──────────────────────────┐
  courseid (null = global)     │
  shortname                    │
  fullname                     │
  scaleid ──── scale.id        │
  description                  │
                               │ (via grade_outcomes_courses)
course ──── grade_outcomes_courses ──── grade_outcomes
  id            courseid
                outcomeid

grade_outcomes.id ──── grade_items.outcomeid
                           │
                           └── grade_grades (per-user scores)
                                 itemid → grade_items.id
                                 userid
                                 finalgrade (scale value)
```

---

## 3. PHP Class — `grade_outcome`

**Location:** `lib/grade/grade_outcome.php`  
**Extends:** `grade_object`  
**Table:** `grade_outcomes`

| Method | Description |
|---|---|
| `insert()` | Creates a new outcome record; also inserts into `grade_outcomes_courses` if `courseid` is set |
| `update()` | Updates an existing outcome |
| `delete()` | Deletes outcome and all linked `grade_outcomes_courses` and `grade_items` rows |
| `use_in($courseid)` | Adds outcome to a course (inserts `grade_outcomes_courses` row) |
| `fetch($params)` | Static — fetch single outcome by params |
| `fetch_all($params)` | Static — fetch all outcomes matching params |
| `fetch_all_global()` | Static — returns all outcomes where `courseid IS NULL` |
| `fetch_all_local($courseid)` | Static — returns outcomes created inside a specific course |
| `fetch_all_available($courseid)` | Static — returns global + course-local outcomes (union) |
| `load_scale()` | Loads and caches the associated `grade_scale` object |
| `get_name()` | Returns `fullname` |
| `get_shortname()` | Returns `shortname` |
| `get_description()` | Returns formatted description |
| `can_delete()` | Returns false if outcome has any linked grade items with grades |
| `get_course_uses_count()` | How many courses use this outcome |
| `get_item_uses_count()` | How many grade items reference this outcome |
| `get_grade_info($courseid, $average, $items)` | Returns per-outcome grade statistics |

---

## 4. Key Source Files

### Core grade library

| File | Role |
|---|---|
| `lib/grade/grade_outcome.php` | Main domain class |
| `lib/gradelib.php` | Public API — `grade_update_outcomes()`, `grade_get_grades()` return outcome data |

### Outcome management UI

| File | Role |
|---|---|
| `grade/edit/outcome/index.php` | List all outcomes (site or course) |
| `grade/edit/outcome/edit.php` | Create / edit a single outcome |
| `grade/edit/outcome/edit_form.php` | Moodle form for create/edit — **enforces `scaleid` as required** |
| `grade/edit/outcome/course.php` | Manage outcomes available in a specific course |
| `grade/edit/outcome/import.php` | CSV import of outcomes |
| `grade/edit/outcome/import_outcomes_form.php` | Form for CSV import |
| `grade/edit/outcome/export.php` | CSV export of outcomes |
| `grade/edit/outcome/tabs.php` | Tab navigation helper |
| `grade/edit/tree/outcomeitem.php` | Grade tree: add outcome as a grade item to an activity |
| `grade/edit/tree/outcomeitem_form.php` | Form for above |
| `grade/classes/form/add_outcome.php` | Fragment form used in some contexts |
| `grade/classes/output/course_outcomes_action_bar.php` | Action bar renderer for course outcomes page |
| `grade/classes/output/manage_outcomes_action_bar.php` | Action bar renderer for site outcomes page |

### Outcomes report

| File | Role |
|---|---|
| `grade/report/outcomes/index.php` | The only report — shows per-outcome average grades and user counts for a course |
| `grade/report/outcomes/db/access.php` | Declares `gradereport/outcomes:view` capability |
| `grade/report/outcomes/classes/event/grade_report_viewed.php` | Fires event when report is viewed |
| `grade/report/outcomes/classes/privacy/provider.php` | Privacy provider (reads `grade_grades` for outcome items) |

### Activity integration (module form)

| File | Role |
|---|---|
| `course/moodleform_mod.php` | Adds outcome checkboxes to every activity form when `$CFG->enableoutcomes` is set and the module declares `FEATURE_GRADE_OUTCOMES => true` |

---

## 5. Scale Coupling — The Hard Constraint

**The problem:** Every outcome must be linked to a scale. This is enforced in two places:

1. **`grade/edit/outcome/edit_form.php` line 55:**
   ```php
   $mform->addRule('scaleid', get_string('required'), 'required');
   ```
   And in `validation()`:
   ```php
   if ($data['scaleid'] < 1) {
       $errors['scaleid'] = get_string('required');
   }
   ```

2. **`grade_outcomes.scaleid`** is not nullable in the DB schema (`TYPE="int"`), though at the DB level a value of 0 or NULL would technically be storable.

**Why it matters for the PRD:** The PRD explicitly wants to remove the workflow requirement (make scale optional) while preserving the data linkage for existing sites. The form validation and the required DB field are the two places to change.

---

## 6. Site Configuration

| Config key | Location | Default | Effect |
|---|---|---|---|
| `enableoutcomes` | `admin/settings/subsystems.php` | `0` (off) | Master switch. Gates all outcome UI, report, and module form integration |
| `grade_aggregateoutcomes` | `admin/settings/grades.php` | — | Site default for whether outcome items are aggregated into category totals |

**How `enableoutcomes` gates behaviour:**
- `course/moodleform_mod.php:192` — outcome checkboxes only shown when enabled
- `grade/report/outcomes/index.php:42` — report redirects away when disabled
- `admin/settings/grades.php:181` — "Outcomes" admin page only shown when enabled
- `mod/assign/locallib.php` — outcome grading only processed when enabled

---

## 7. Capabilities

| Capability | Context | Archetypes | Purpose |
|---|---|---|---|
| `moodle/grade:manageoutcomes` | `CONTEXT_COURSE` | `editingteacher`, `manager` | Create, edit, delete outcomes at course or site level |
| `gradereport/outcomes:view` | `CONTEXT_COURSE` | `teacher`, `editingteacher`, `manager` | View the outcomes grading report |

There is **no separate capability** for viewing outcomes as a student. Student visibility is currently zero — outcomes are not surfaced anywhere on the student-facing course page.

---

## 8. Module Support (`FEATURE_GRADE_OUTCOMES`)

### Opt-in (support outcomes)

| Module |
|---|
| `assign` |
| `data` |
| `forum` |
| `glossary` |
| `h5pactivity` |
| `lesson` |
| `lti` |
| `quiz` |
| `reengagement` |
| `scorm` |
| `wiki` |

### Opt-out (explicitly `false`)

| Module | Notes |
|---|---|
| `bigbluebuttonbn` | Conference tool, no grading |
| `book` | Resource type |
| `choice` | Simple poll |
| `feedback` | Survey-style, no grade |
| `folder` | Resource type |
| `imscp` | Content package |
| `label` | Decorative — relevant to "decorative activity" classification in PRD |
| `page` | Resource type |
| `qbank` | Question bank, not directly graded |
| `resource` | File resource |
| `subsection` | Navigation |
| `url` | Resource type |
| `wiki` | Collaborative, opts out |

> `label` opting out is architecturally significant: it is the canonical example of a "decorative activity" in the PRD terminology.

---

## 9. Backup and Restore

Outcomes are carried through course backup/restore via the Moodle2 backup framework.

| Class / Step | File | What it does |
|---|---|---|
| `backup_final_outcomes_structure_step` | `backup/moodle2/backup_stepslib.php:877` | Serialises all outcomes referenced in the backup into `outcomes.xml` |
| `backup_annotate_scales_from_outcomes` | `backup/moodle2/backup_stepslib.php:2523` | Ensures the scales referenced by outcomes are also backed up |
| `restore_outcomes_structure_step` | `backup/moodle2/restore_stepslib.php:1476` | Restores outcomes from `outcomes.xml`; deduplicates by shortname; re-creates `grade_outcomes_courses` rows |
| Grade items restore | `backup/moodle2/restore_stepslib.php:205` | Remaps `outcomeid` on `grade_items` to the newly restored outcome IDs |

**Known limitation (comment in code, line ~4104):**
```
// This needs to be fixed in some way (outcomes & activities with multiple items)
```
The backup code has a known rough edge when activities have multiple grade items combined with outcomes.

---

## 10. The `grade_update_outcomes()` Public API

Located in `lib/gradelib.php`. Called by activity modules to submit per-user outcome grades.

```php
grade_update_outcomes($source, $courseid, $itemtype, $itemmodule, $iteminstance, $userid, $data)
```

- `$data` is an array of `itemnumber => grade_value`
- Calls `grade_item::update_final_grade()` for each matched outcome item
- Does not validate whether outcomes are enabled — callers guard with `$CFG->enableoutcomes`

The `grade_get_grades()` function (same file) returns outcomes separately from regular grade items — the response object has both `->items` and `->outcomes` arrays. Outcome items are identified by `grade_items.outcomeid` being non-null.

---

## 11. How Outcome Grading Works End-to-End

```
Teacher enables outcomes → adds outcome to course
    → outcome row in grade_outcomes
    → link row in grade_outcomes_courses

Teacher opens activity settings form
    → course/moodleform_mod.php injects outcome checkboxes
    → teacher ticks outcome checkbox(es) → saves

On save, for each ticked outcome:
    → grade_item created with outcomeid = outcome.id, itemnumber > 0
    → scaleid copied from outcome to grade_item
    → gradetype = GRADE_TYPE_SCALE

When activity grades a user:
    → module calls grade_update_outcomes()
    → grade_item::update_final_grade() writes to grade_grades
    → grade is a scale value (integer index into scale items)

grade_get_grades() returns outcome grades in ->outcomes array
    → read by mod_assign grading table, etc.
```

---

## 12. Open Questions for PRD Implementation

| # | Question | Where it matters |
|---|---|---|
| Q1 | What is the DB migration path for `scaleid` to become nullable? | Scale decoupling epic |
| Q2 | Does `grade_outcome::insert()` need to create a `grade_item` automatically, or should that remain a teacher manual action? | Teacher authoring workflow |
| Q3 | How should outcomes with `courseid = NULL` (global) be handled in the "meaningful use" upgrade audit? | Upgrade-time audit epic |
| Q4 | The existing outcomes report (`grade/report/outcomes/`) is grading-centric (shows per-user scale scores). The new Alignment report is coverage-centric (shows which activities are tagged). Are these the same plugin or separate? | Alignment report epic |
| Q5 | `grade_items.outcomeid` couples tagging to grading. The PRD wants tagging independent of grading. Does a separate tagging table need to be introduced, or can `outcomeid` be reused? | Activity-tagging data model decision |
| Q6 | `label` explicitly opts out of `FEATURE_GRADE_OUTCOMES`. It should be the canonical "decorative activity" — confirm this is the right heuristic. | Decorative activity classification |
| Q7 | `grade_outcomes_history` exists but has no current UI. Should it be wired into the new LO audit trail? | Upgrade audit |

---

## 13. Files to Read Next

Recommended reading order before starting any epic:

1. `lib/grade/grade_outcome.php` — full class (429 lines)
2. `grade/edit/outcome/edit_form.php` — to understand scale coupling in detail
3. `course/moodleform_mod.php` lines 185–530 — to see exactly how outcome checkboxes are injected into activity forms
4. `lib/grade/grade_item.php` — `is_outcome_item()`, `insert()` with outcomeid path
5. `backup/moodle2/backup_stepslib.php` lines 874–910 and 2521–2540 — backup steps
6. `backup/moodle2/restore_stepslib.php` lines 1474–1540 — restore steps
7. `admin/settings/subsystems.php` lines 1–15 — `enableoutcomes` checkbox
