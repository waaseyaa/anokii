// Fictional needs-review workflow (scenario B). Decisions live only in the
// page until it reloads; applying them is simulated, and nothing is saved or
// sent.
import { showOutcomes, showStep } from '/anokii-demo/primitives.js';

const root = document.querySelector('[data-review]');

if (root) {
  const steps = root.querySelector('.anokii-demo-steps');
  const sections = [...root.querySelectorAll('[data-step]')];
  const failFirst = (root.dataset.failFirst ?? '').split(' ').filter(Boolean);
  const items = [...root.querySelectorAll('[data-item]')];
  const summary = root.querySelector('[data-summary]');
  const outcomes = root.querySelector('.anokii-demo-outcomes');
  const labels = { approve: 'Approve', 'send-back': 'Send back' };
  const results = { approve: 'approved', 'send-back': 'sent back' };

  const decision = item => item.querySelector('input[type="radio"]:checked')?.value ?? 'later';
  const note = item => item.querySelector('textarea');
  const decided = () => items.filter(item => decision(item) !== 'later');
  const error = (index, message, focus) => {
    sections[index].querySelector('[data-error]').textContent = message;
    focus?.focus();
  };
  const go = index => {
    root.querySelectorAll('[data-error]').forEach(node => { node.textContent = ''; });
    showStep(steps, sections, index);
  };

  // Sending an item back needs a note; the note field appears only then.
  items.forEach(item => item.addEventListener('change', event => {
    if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'radio') {
      return;
    }
    const sendBack = decision(item) === 'send-back';
    item.querySelector('[data-note]').hidden = !sendBack;
    note(item).removeAttribute('aria-invalid');
  }));
  items.forEach(item => note(item).addEventListener('input', () => {
    if (note(item).value.trim() !== '') {
      note(item).removeAttribute('aria-invalid');
    }
  }));

  root.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('button') : null;
    if (!button || !root.contains(button)) {
      return;
    }
    if (button.matches('[data-next]')) {
      if (decided().length === 0) {
        error(0, 'Choose Approve or Send back for at least one item.', items[0].querySelector('input'));
        return;
      }
      const missing = decided().find(item => decision(item) === 'send-back' && note(item).value.trim() === '');
      if (missing) {
        note(missing).setAttribute('aria-invalid', 'true');
        error(0, `Add a note for "${missing.dataset.title}" before sending it back.`, note(missing));
        return;
      }
      summary.replaceChildren(...decided().map(item => {
        const row = document.createElement('li');
        const action = document.createElement('strong');
        action.textContent = `${labels[decision(item)]}: `;
        row.append(action, item.dataset.title);
        if (decision(item) === 'send-back') {
          row.append(`. Note: ${note(item).value.trim()}`);
        }
        return row;
      }));
      go(1);
    } else if (button.matches('[data-back]')) {
      go(0);
    } else if (button.matches('[data-apply]')) {
      const targets = decided().map(item => ({
        id: item.dataset.item,
        label: `${item.dataset.title} (${results[decision(item)]})`,
      }));
      go(2);
      showOutcomes(outcomes, targets, {
        failFirst,
        doneText: 'Applied',
        failedText: 'Failed',
        simulatedText: 'Simulated: nothing saved',
      });
    } else if (button.matches('[data-restart]')) {
      items.forEach(item => {
        item.querySelector('input[value="later"]').checked = true;
        item.querySelector('[data-note]').hidden = true;
        note(item).value = '';
        note(item).removeAttribute('aria-invalid');
      });
      go(0);
    }
  });
}
