// Behaviour for the Anokii operator demo primitives (see primitives.html.twig).
// Everything is simulated in the page: nothing here reads files, stores data,
// or opens a connection.

/** Marks step `index` of a steps list as current and every earlier step as done. */
export function setStep(list, index) {
  list.querySelectorAll(':scope > li').forEach((item, position) => {
    const state = position < index ? 'done' : position === index ? 'current' : 'todo';
    item.dataset.state = state;
    if (state === 'current') {
      item.setAttribute('aria-current', 'step');
    } else {
      item.removeAttribute('aria-current');
    }
    item.querySelector('.anokii-demo-vh')?.remove();
    if (state === 'done') {
      const note = document.createElement('span');
      note.className = 'anokii-demo-vh';
      note.textContent = ' (done)';
      item.append(note);
    }
  });
}

/**
 * Shows the step section at `index`, hides the others, updates the step list,
 * and moves focus to the shown section's first heading so the change is
 * announced.
 */
export function showStep(list, sections, index) {
  sections.forEach((section, position) => {
    section.hidden = position !== index;
  });
  setStep(list, index);
  const heading = sections[index]?.querySelector('h2, h3');
  if (heading) {
    heading.tabIndex = -1;
    heading.focus();
  }
}

/**
 * Fills an outcome list with one simulated result per target ({ id, label }).
 * Targets whose id is in `failFirst` fail on the first attempt and offer Retry,
 * which always succeeds. After a retry, focus moves to that row's new state.
 */
export function showOutcomes(container, targets, options = {}) {
  const {
    failFirst = [],
    doneText = 'Done',
    failedText = 'Failed',
    simulatedText = 'Simulated: nothing sent',
  } = options;
  const list = container.querySelector('.anokii-demo-outcome-list');
  const status = container.querySelector('.anokii-demo-outcome-status');
  const failing = new Set(failFirst);

  const summarize = () => {
    const failed = list.querySelectorAll('li[data-state="failed"]').length;
    const done = targets.length - failed;
    status.textContent = failed === 0
      ? `All ${targets.length} done. ${simulatedText}.`
      : `${done} of ${targets.length} done, ${failed} failed. Use Retry to try again.`;
  };

  const render = (row, target, state) => {
    row.dataset.state = state;
    const name = document.createElement('span');
    name.className = 'anokii-demo-outcome-target';
    name.textContent = target.label;
    const result = document.createElement('span');
    result.className = 'anokii-demo-outcome-state';
    result.tabIndex = -1;
    result.textContent = state === 'done' ? doneText : failedText;
    const label = document.createElement('span');
    label.className = 'anokii-demo-sim';
    label.textContent = simulatedText;
    row.replaceChildren(name, result, label);
    if (state === 'failed') {
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'anokii-demo-retry';
      const hidden = document.createElement('span');
      hidden.className = 'anokii-demo-vh';
      hidden.textContent = ` ${target.label}`;
      retry.append('Retry', hidden);
      retry.addEventListener('click', () => {
        render(row, target, 'done');
        summarize();
        row.querySelector('.anokii-demo-outcome-state').focus();
      });
      row.append(retry);
    }
  };

  list.replaceChildren(...targets.map(target => {
    const row = document.createElement('li');
    render(row, target, failing.has(target.id) ? 'failed' : 'done');
    return row;
  }));
  // A live region only announces changes made after it is rendered. The list
  // usually sits in a section that was hidden until now, so update the status
  // a moment later. A timer, unlike an animation frame, also runs while the
  // page is not being painted.
  status.textContent = '';
  setTimeout(summarize, 100);
}
