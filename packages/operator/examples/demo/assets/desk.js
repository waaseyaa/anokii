// Fictional communications desk (scenario A). Everything is simulated in the
// page: a chosen file's name is the only thing kept, and nothing is saved,
// published, or sent.
import { showOutcomes, showStep } from '/anokii-demo/primitives.js';

const root = document.querySelector('[data-desk]');

if (root) {
  const steps = root.querySelector('.anokii-demo-steps');
  const sections = [...root.querySelectorAll('[data-step]')];
  const failFirst = (root.dataset.failFirst ?? '').split(' ').filter(Boolean);
  const file = root.querySelector('[data-file]');
  const drop = root.querySelector('[data-drop]');
  const sourceStatus = root.querySelector('[data-source-status]');
  const samples = [...root.querySelectorAll('input[name="desk-sample"]')];
  const audiences = [...root.querySelectorAll('input[name="desk-audience"]')];
  const channelGroups = [...root.querySelectorAll('fieldset[data-audience]')];
  const drafts = [...root.querySelectorAll('[data-draft]')];
  const approve = root.querySelector('[data-approve]');
  const outcomes = root.querySelector('.anokii-demo-outcomes');
  let source = '';

  const error = (index, message, focus) => {
    sections[index].querySelector('[data-error]').textContent = message;
    focus?.focus();
  };
  const clearErrors = () => root.querySelectorAll('[data-error]').forEach(node => { node.textContent = ''; });
  const go = index => {
    clearErrors();
    showStep(steps, sections, index);
  };

  // Step 1: the source. Only a file's name is read from the file input, and
  // the input is cleared at once so the page holds no reference to the file.
  const setSource = (name, how) => {
    source = name;
    sourceStatus.textContent = name === '' ? '' : `Source: ${name} (${how}).`;
  };
  file.addEventListener('change', () => {
    const name = file.files?.[0]?.name ?? '';
    file.value = '';
    if (name === '') {
      return;
    }
    samples.forEach(sample => { sample.checked = false; });
    setSource(name, 'file name only; the file was not opened');
  });
  samples.forEach(sample => sample.addEventListener('change', () => setSource(sample.dataset.title, 'sample')));
  ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, () => drop.classList.add('is-over')));
  ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, () => drop.classList.remove('is-over')));
  // A file dropped anywhere else would make the browser open it; refuse that.
  ['dragover', 'drop'].forEach(type => document.addEventListener(type, event => {
    if (event.target !== file) {
      event.preventDefault();
    }
  }));

  // Step 2: the audience decides which channels exist. Choosing an audience
  // clears the other audience's channels, so a members-only update can never
  // carry a public or social channel.
  const audience = () => audiences.find(input => input.checked)?.value ?? '';
  const activeGroup = () => channelGroups.find(group => group.dataset.audience === audience());
  const chosenChannels = () => [...(activeGroup()?.querySelectorAll('input:checked') ?? [])];
  audiences.forEach(input => input.addEventListener('change', () => {
    channelGroups.forEach(group => {
      const active = group === activeGroup();
      group.hidden = !active;
      if (!active) {
        group.querySelectorAll('input').forEach(box => { box.checked = false; });
      }
    });
  }));

  root.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('button') : null;
    if (!button || !root.contains(button)) {
      return;
    }
    const index = sections.findIndex(section => section.contains(button));
    if (button.matches('[data-back]')) {
      go(index - 1);
    } else if (button.matches('[data-next]') && index === 0) {
      if (source === '') {
        error(0, 'Choose a document or a sample source to continue.', samples[0]);
        return;
      }
      go(1);
    } else if (button.matches('[data-next]') && index === 1) {
      if (audience() === '') {
        error(1, 'Choose who this update is for.', audiences[0]);
        return;
      }
      if (chosenChannels().length === 0) {
        error(1, 'Choose at least one channel.', activeGroup()?.querySelector('input'));
        return;
      }
      // Drafts and approval always match the current audience and channels.
      const chosen = new Set(chosenChannels().map(box => box.value));
      drafts.forEach(draft => { draft.hidden = !chosen.has(draft.dataset.draft); });
      root.querySelectorAll('[data-source-name]').forEach(node => { node.textContent = source; });
      approve.checked = false;
      go(2);
    } else if (button.matches('[data-publish]')) {
      if (!approve.checked) {
        error(2, 'Approve the drafts before publishing.', approve);
        return;
      }
      const targets = chosenChannels().map(box => ({ id: box.value, label: box.dataset.label }));
      go(3);
      showOutcomes(outcomes, targets, {
        failFirst,
        doneText: 'Published',
        failedText: 'Failed',
        simulatedText: 'Simulated: nothing published',
      });
    } else if (button.matches('[data-restart]')) {
      reset();
      go(0);
    }
  });

  // Back and Forward can bring the page back with choices the browser
  // restored but this script never saw, so every showing starts clean.
  function reset() {
    setSource('', '');
    [...samples, ...audiences, approve].forEach(input => { input.checked = false; });
    channelGroups.forEach(group => {
      group.hidden = true;
      group.querySelectorAll('input').forEach(box => { box.checked = false; });
    });
  }
  window.addEventListener('pageshow', reset);
}
