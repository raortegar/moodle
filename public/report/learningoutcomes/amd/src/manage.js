// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AMD module for the Manage learning outcomes page.
 *
 * Handles:
 *  - Removing an activity from an outcome (deferred 10-second undo).
 *  - Adding an activity to an outcome (immediate AJAX, DOM update).
 *
 * @module     report_learningoutcomes/manage
 * @copyright  2026 Moodle HQ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';

/** @type {number} */
let courseId;

/** Pending deferred removals keyed by `${outcomeid}-${cmid}`. */
const pending = {};

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Escapes a string for safe insertion into HTML.
 *
 * @param {string} str
 * @returns {string}
 */
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Posts to the plugin's ajax.php endpoint.
 *
 * @param {string} action   'tag' or 'untag'
 * @param {string} cmid     Course-module ID.
 * @param {string} outcomeid Outcome ID.
 * @returns {Promise<Object>}
 */
async function callAjax(action, cmid, outcomeid) {
    const body = new URLSearchParams({
        action,
        cmid,
        outcomeid,
        courseid: courseId,
        sesskey: M.cfg.sesskey,
    });

    const response = await fetch(
        M.cfg.wwwroot + '/report/learningoutcomes/ajax.php',
        {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body,
        }
    );

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    const data = await response.json();
    if (!data.success) {
        throw new Error(data.error ?? 'Unknown error');
    }
    return data;
}

// ── Undo toast ───────────────────────────────────────────────────────────────

/**
 * Inserts an in-card undo notification with a 10-second countdown.
 *
 * @param {string}   activityname  Display name of the removed activity.
 * @param {string}   outcomeid     Outcome ID (for locating the card).
 * @param {string}   cmid          CM ID (used as part of the token).
 * @param {Function} onUndo        Called immediately when the user clicks Undo.
 */
function showUndoAlert(activityname, outcomeid, cmid, onUndo) {
    const token = `${outcomeid}-${cmid}`;

    const alert = document.createElement('div');
    alert.className = 'alert alert-info alert-dismissible lo-undo-alert d-flex align-items-center gap-2 py-2';
    alert.setAttribute('role', 'status');
    alert.innerHTML = `
        <span>
            <strong>${escHtml(activityname)}</strong> removed from outcome.
        </span>
        <a href="#" class="lo-undo-link alert-link ms-1">Undo</a>
        <span class="text-muted small ms-auto lo-countdown">(10s)</span>
        <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
    `;

    const card = document.querySelector(`.lo-outcome-section[data-outcomeid="${outcomeid}"] .card-body`);
    if (card) {
        card.prepend(alert);
    }

    // Countdown.
    let remaining = 10;
    const countdownEl = alert.querySelector('.lo-countdown');
    const interval = setInterval(() => {
        remaining--;
        if (countdownEl) {
            countdownEl.textContent = `(${remaining}s)`;
        }
        if (remaining <= 0) {
            clearInterval(interval);
        }
    }, 1000);

    // Auto-dismiss after 10 s (the AJAX call has already been made by then).
    const dismissTimer = setTimeout(() => {
        clearInterval(interval);
        alert.remove();
        delete pending[token];
    }, 10000);

    // Undo action.
    alert.querySelector('.lo-undo-link').addEventListener('click', (e) => {
        e.preventDefault();
        clearTimeout(dismissTimer);
        clearInterval(interval);
        alert.remove();
        const entry = pending[token];
        delete pending[token];
        if (entry) {
            onUndo(entry);
        }
    });

    pending[token] = {dismissTimer, interval};
}

// ── Remove handler ────────────────────────────────────────────────────────────

/**
 * Handles clicks on .lo-remove-btn.
 *
 * Immediately hides the row and schedules the AJAX call 10 s later.
 * If the user clicks Undo the row is restored and the AJAX is cancelled.
 *
 * @param {Event} e
 */
async function handleRemove(e) {
    const btn = e.target.closest('.lo-remove-btn');
    if (!btn) {
        return;
    }

    const {cmid, outcomeid, activityname} = btn.dataset;
    const row = btn.closest('tr');
    const token = `${outcomeid}-${cmid}`;

    // Cancel any existing deferred removal for this row.
    if (pending[token]) {
        clearTimeout(pending[token].ajaxTimer);
    }

    // Visually hide the row immediately.
    row.style.display = 'none';
    maybeShowEmptyPlaceholder(outcomeid);

    // Schedule the AJAX call for 10 s from now.
    let undone = false;

    const ajaxTimer = setTimeout(async() => {
        if (undone) {
            return;
        }
        try {
            await callAjax('untag', cmid, outcomeid);
            row.remove();
            maybeShowEmptyPlaceholder(outcomeid);
        } catch (err) {
            // Server error — restore the row.
            row.style.display = '';
            maybeShowEmptyPlaceholder(outcomeid);
            Notification.exception(err);
        }
        delete pending[token];
    }, 10000);

    pending[token] = {ajaxTimer};

    showUndoAlert(activityname, outcomeid, cmid, () => {
        // Undo: cancel the timer and restore the row.
        undone = true;
        clearTimeout(ajaxTimer);
        delete pending[token];
        row.style.display = '';
        maybeShowEmptyPlaceholder(outcomeid);
    });
}

// ── Add handler ───────────────────────────────────────────────────────────────

/**
 * Handles clicks on .lo-add-btn.
 *
 * Reads the selected cm from the accompanying <select>, calls AJAX immediately,
 * then inserts the returned row HTML and removes the option from the select.
 *
 * @param {Event} e
 */
async function handleAdd(e) {
    const btn = e.target.closest('.lo-add-btn');
    if (!btn) {
        return;
    }

    const {outcomeid} = btn.dataset;
    const form = btn.closest('.lo-add-form');
    const select = form ? form.querySelector('.lo-add-select') : null;
    const cmid = select ? select.value : '';

    if (!cmid) {
        return;
    }

    btn.disabled = true;

    try {
        const result = await callAjax('tag', cmid, outcomeid);

        // Insert the new row into the outcome's table.
        const tbody = document.querySelector(
            `.lo-outcome-section[data-outcomeid="${outcomeid}"] .lo-activities-table tbody`
        );
        if (tbody) {
            // Remove the "no activities" placeholder row if present.
            const placeholder = tbody.querySelector('.lo-empty-row');
            if (placeholder) {
                placeholder.closest('tr').remove();
            }
            tbody.insertAdjacentHTML('beforeend', result.rowhtml);
        }

        // Remove the now-linked option from the select.
        const opt = select.querySelector(`option[value="${cmid}"]`);
        if (opt) {
            opt.remove();
        }
        select.value = '';
    } catch (err) {
        Notification.exception(err);
    }

    btn.disabled = false;
}

// ── Utility ───────────────────────────────────────────────────────────────────

/**
 * Shows or hides the "no activities linked" placeholder row based on visible rows.
 *
 * @param {string} outcomeid
 */
function maybeShowEmptyPlaceholder(outcomeid) {
    const tbody = document.querySelector(
        `.lo-outcome-section[data-outcomeid="${outcomeid}"] .lo-activities-table tbody`
    );
    if (!tbody) {
        return;
    }

    const dataRows = tbody.querySelectorAll('.lo-activity-row');
    const visibleRows = Array.from(dataRows).filter(r => r.style.display !== 'none');
    const existing = tbody.querySelector('.lo-empty-row');

    if (visibleRows.length === 0 && !existing) {
        // Build and insert a placeholder.
        const colspan = tbody.closest('table').querySelectorAll('thead th').length;
        const placeholder = document.createElement('tr');
        const msg = 'No activities linked to this outcome yet.';
        placeholder.innerHTML =
            `<td class="lo-empty-row" colspan="${colspan}"><em class="text-muted">${msg}</em></td>`;
        tbody.appendChild(placeholder);
    } else if (visibleRows.length > 0 && existing) {
        existing.closest('tr').remove();
    }
}

// ── Init ──────────────────────────────────────────────────────────────────────

/**
 * Initialises the manage page interactions.
 *
 * @param {Object} config
 * @param {number} config.courseid
 */
export const init = (config) => {
    courseId = config.courseid;

    document.addEventListener('click', handleRemove);
    document.addEventListener('click', handleAdd);
};
