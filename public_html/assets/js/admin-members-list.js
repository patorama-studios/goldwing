(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-members-list]');
    if (!root) {
      return;
    }

    const configEl = root.querySelector('[data-members-config]');
    let csrfToken = '';
    let chapters = [];
    let statuses = [];
    if (configEl && configEl.dataset.membersConfig) {
      try {
        const parsed = JSON.parse(configEl.dataset.membersConfig);
        csrfToken = parsed.csrf || '';
        chapters = parsed.chapters || [];
        statuses = parsed.statuses || [];
      } catch (error) {
        console.error('Failed to parse members list config', error);
      }
    }

    const toolbar = root.querySelector('[data-bulk-toolbar]');
    const selectionCountEl = toolbar?.querySelector('[data-selected-count]');
    const bulkMessageEl = root.querySelector('[data-bulk-message]');
    const selectAllPageBtn = toolbar?.querySelector('[data-select-all-page]');
    const masterCheckbox = root.querySelector('[data-select-all]');
    const rowCheckboxes = Array.from(root.querySelectorAll('[data-member-checkbox]'));
    const selectedMembers = new Set();

    const statusBadgeClasses = {
      active: 'bg-green-100 text-green-800',
      pending: 'bg-yellow-100 text-yellow-800',
      expired: 'bg-red-100 text-red-800',
      cancelled: 'bg-amber-100 text-amber-800',
      suspended: 'bg-indigo-100 text-indigo-800',
    };

    const updateToolbar = () => {
      if (toolbar) {
        toolbar.classList.toggle('hidden', selectedMembers.size === 0);
      }
      if (selectionCountEl) {
        selectionCountEl.textContent = selectedMembers.size.toString();
      }
      if (masterCheckbox) {
        const allChecked = rowCheckboxes.length > 0 && rowCheckboxes.every((checkbox) => checkbox.checked);
        masterCheckbox.checked = allChecked;
      }
    };

    const showBulkMessage = (text, tone = 'text-gray-600') => {
      if (!bulkMessageEl) return;
      bulkMessageEl.textContent = text;
      bulkMessageEl.className = `px-5 py-3 text-sm ${tone}`;
      bulkMessageEl.classList.remove('hidden');
      window.setTimeout(() => {
        bulkMessageEl.classList.add('hidden');
      }, 4000);
    };

    const toggleRowSelection = (checkbox) => {
      const memberId = checkbox.value;
      if (!memberId) {
        return;
      }
      if (checkbox.checked) {
        selectedMembers.add(memberId);
      } else {
        selectedMembers.delete(memberId);
      }
      updateToolbar();
    };

    rowCheckboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', () => toggleRowSelection(checkbox));
    });

    masterCheckbox?.addEventListener('change', () => {
      const targetChecked = masterCheckbox.checked;
      rowCheckboxes.forEach((checkbox) => {
        checkbox.checked = targetChecked;
        toggleRowSelection(checkbox);
      });
    });

    selectAllPageBtn?.addEventListener('click', (event) => {
      event.preventDefault();
      rowCheckboxes.forEach((checkbox) => {
        checkbox.checked = true;
        selectedMembers.add(checkbox.value);
      });
      updateToolbar();
    });

    const inlineContainers = root.querySelectorAll('[data-inline-field]');

    const parseJsonResponse = async (response) => {
      const text = await response.text();
      try {
        return JSON.parse(text);
      } catch (error) {
        console.error('Invalid JSON response for members action:', text);
        throw new Error('Server response could not be parsed.');
      }
    };

    const inlineRequest = async (memberId, field, value) => {
      if (!csrfToken) {
        throw new Error('Missing CSRF token.');
      }
      const form = new FormData();
      form.append('csrf_token', csrfToken);
      form.append('action', 'member_inline_update');
      form.append('inline_member_id', memberId);
      form.append('inline_field', field);
      form.append('inline_value', value);
      form.append('inline_ajax', '1');
      const response = await fetch('/admin/members/actions.php', {
        method: 'POST',
        body: form,
      });
      if (!response.ok) {
        const errorPayload = await parseJsonResponse(response).catch(() => null);
        throw new Error(errorPayload?.error || 'Network error.');
      }
      const payload = await parseJsonResponse(response);
      if (!payload.success) {
        throw new Error(payload.error || 'Could not save. Try again.');
      }
      return payload;
    };

    const showInlineFeedback = (container, message, type = 'info') => {
      const feedback = container.querySelector('[data-inline-feedback]');
      if (!feedback) {
        return;
      }
      feedback.textContent = message;
      feedback.classList.remove('text-gray-500', 'text-rose-500', 'text-emerald-600', 'hidden');
      if (type === 'error') {
        feedback.classList.add('text-rose-500');
      } else if (type === 'success') {
        feedback.classList.add('text-emerald-600');
      } else {
        feedback.classList.add('text-gray-500');
      }
    };

    const toggleEditorVisibility = (container, visible) => {
      const trigger = container.querySelector('[data-inline-trigger]');
      const editor = container.querySelector('[data-inline-editor]');
      if (visible) {
        trigger?.classList.add('hidden');
        editor?.classList.remove('hidden');
        const select = container.querySelector('[data-inline-input]');
        select?.focus();
      } else {
        trigger?.classList.remove('hidden');
        editor?.classList.add('hidden');
      }
    };

    inlineContainers.forEach((container) => {
      const field = container.dataset.field;
      const memberId = container.dataset.memberId;
      const trigger = container.querySelector('[data-inline-trigger]');
      const select = container.querySelector('[data-inline-input]');
      const toggle = container.querySelector('[data-inline-toggle]');
      const valueEl = container.querySelector('[data-inline-value]');
      const badge = container.querySelector('[data-inline-badge]');

      trigger?.addEventListener('click', () => {
        toggleEditorVisibility(container, true);
      });

      select?.addEventListener('change', async () => {
        showInlineFeedback(container, 'Saving…');
        select.disabled = true;
        try {
          const payload = await inlineRequest(memberId, field, select.value);
          if (payload.label && valueEl) {
            valueEl.textContent = payload.label;
          }
          if (field === 'status' && badge) {
            badge.textContent = payload.label;
            badge.className = `inline-flex rounded-full px-3 py-1 text-xs font-semibold ${statusBadgeClasses[select.value] || 'bg-slate-100 text-slate-800'}`;
          }
          showInlineFeedback(container, 'Saved', 'success');
        } catch (error) {
          showInlineFeedback(container, error.message || 'Save failed', 'error');
        } finally {
          select.disabled = false;
          toggleEditorVisibility(container, false);
        }
      });

      toggle?.addEventListener('click', async () => {
        const desiredState = toggle.dataset.state === '1' ? '0' : '1';
        toggle.disabled = true;
        showInlineFeedback(container, 'Saving…');
        try {
          const payload = await inlineRequest(memberId, field, desiredState);
          if (payload.label && valueEl) {
            valueEl.textContent = payload.label;
          }
          const state = payload.state ?? desiredState;
          toggle.dataset.state = state;
          toggle.textContent = state === '1' ? 'Set optional' : 'Require 2FA';
          toggle.setAttribute('aria-pressed', state === '1' ? 'true' : 'false');
          showInlineFeedback(container, 'Saved', 'success');
        } catch (error) {
          showInlineFeedback(container, error.message || 'Save failed', 'error');
        } finally {
          toggle.disabled = false;
        }
      });
    });

    const modalTemplate = `
      <div class="hidden fixed inset-0 z-50 flex items-center justify-center" data-bulk-modal>
        <div class="absolute inset-0 bg-black/40"></div>
        <div class="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-soft">
          <div>
            <h3 class="text-lg font-semibold text-gray-900" data-modal-title></h3>
            <p class="text-sm text-gray-500 mt-1" data-modal-description></p>
          </div>
          <form class="mt-4 space-y-4" data-modal-form>
            <div class="space-y-4" data-modal-fields></div>
            <div class="flex items-center justify-end gap-2 pt-4 border-t border-gray-100">
              <button type="button" class="rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-600" data-modal-cancel>Cancel</button>
              <button type="submit" class="rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900" data-modal-submit>Confirm action</button>
            </div>
          </form>
        </div>
      </div>`;

    const modalWrapper = document.createElement('div');
    modalWrapper.innerHTML = modalTemplate;
    const modal = modalWrapper.firstElementChild;
    if (!modal) return;
    document.body.appendChild(modal);
    const modalTitle = modal.querySelector('[data-modal-title]');
    const modalDescription = modal.querySelector('[data-modal-description]');
    const modalFields = modal.querySelector('[data-modal-fields]');
    const modalForm = modal.querySelector('[data-modal-form]');
    const modalCancel = modal.querySelector('[data-modal-cancel]');
    const modalSubmit = modal.querySelector('[data-modal-submit]');

    const closeModal = () => {
      if (!modal) return;
      modal.classList.add('hidden');
    };

    const openModal = () => {
      if (!modal) return;
      modal.classList.remove('hidden');
    };

    modalCancel?.addEventListener('click', (event) => {
      event.preventDefault();
      closeModal();
    });

    modal?.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal();
      }
    });

    const bulkActionDefinitions = {
      archive: {
        title: 'Archive selected members',
        description: 'Status will be set to Archived (cancelled).',
        confirmLabel: 'Archive members',
      },
      delete: {
        title: 'Delete members permanently',
        description: 'This removes the member and related data. Type CONFIRM to proceed.',
        confirmLabel: 'Delete members',
        fields: [
          {
            name: 'confirm',
            label: 'Type CONFIRM to continue',
            type: 'text',
            placeholder: 'CONFIRM',
            required: true,
            validate: (value) => value.trim().toUpperCase() === 'CONFIRM',
            invalidMessage: 'Please type CONFIRM to delete.',
          },
        ],
      },
      assign_chapter: {
        title: 'Assign chapter',
        description: 'Select a chapter to assign to all selected members.',
        confirmLabel: 'Assign chapter',
        fields: [
          {
            name: 'chapter_id',
            label: 'Chapter',
            type: 'select',
            options: chapters,
            required: true,
          },
        ],
      },
      change_status: {
        title: 'Change member status',
        description: 'Provide a reason for the status change.',
        confirmLabel: 'Update status',
        fields: [
          {
            name: 'status',
            label: 'Status',
            type: 'select',
            options: statuses.map((status) => ({
              id: status,
              label: status === 'cancelled' ? 'Archived' : status.charAt(0).toUpperCase() + status.slice(1),
            })),
            required: true,
          },
          {
            name: 'reason',
            label: 'Reason',
            type: 'textarea',
            required: true,
            placeholder: 'Why are you applying this status?',
          },
        ],
      },
      enable_2fa: {
        title: 'Require 2FA for selected members',
        description: 'Members will be forced to enroll in 2FA on next login.',
        confirmLabel: 'Require 2FA',
      },
      send_reset_link: {
        title: 'Send password reset link',
        description: 'Respects existing rate limits (max 3 per hour per admin/member).',
        confirmLabel: 'Send reset links',
      },
    };

    const renderField = (field) => {
      const wrapper = document.createElement('div');
      const label = document.createElement('label');
      label.className = 'text-xs font-semibold text-gray-600 block';
      label.textContent = field.label || '';
      wrapper.appendChild(label);

      if (field.type === 'select') {
        const select = document.createElement('select');
        select.name = field.name;
        select.className = 'mt-1 w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-800 focus:border-primary focus:ring-2 focus:ring-primary/30';
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = field.placeholder || 'Select...';
        select.appendChild(placeholderOption);
        (field.options || []).forEach((option) => {
          const optionEl = document.createElement('option');
          optionEl.value = option.id;
          optionEl.textContent = option.label;
          select.appendChild(optionEl);
        });
        wrapper.appendChild(select);
      } else if (field.type === 'textarea') {
        const textarea = document.createElement('textarea');
        textarea.name = field.name;
        textarea.rows = 3;
        textarea.placeholder = field.placeholder || '';
        textarea.className = 'mt-1 w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-800 focus:border-primary focus:ring-2 focus:ring-primary/30';
        wrapper.appendChild(textarea);
      } else {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = field.name;
        input.placeholder = field.placeholder || '';
        input.className = 'mt-1 w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-800 focus:border-primary focus:ring-2 focus:ring-primary/30';
        wrapper.appendChild(input);
      }

      const hint = document.createElement('p');
      hint.className = 'text-xs text-rose-500 hidden';
      hint.dataset.fieldHint = field.name;
      wrapper.appendChild(hint);
      return wrapper;
    };

    const openBulkModal = (actionKey) => {
      const definition = bulkActionDefinitions[actionKey];
      if (!definition) {
        console.warn(`Missing bulk action definition for ${actionKey}`);
        return;
      }
      if (!modal) return;
      modal.dataset.actionKey = actionKey;
      if (modalTitle) {
        modalTitle.textContent = definition.title;
      }
      if (modalDescription) {
        modalDescription.textContent = definition.description || '';
      }
      if (modalSubmit) {
        modalSubmit.textContent = definition.confirmLabel || 'Confirm action';
      }
      if (modalFields) {
        modalFields.innerHTML = '';
        (definition.fields || []).forEach((field) => {
          modalFields.appendChild(renderField(field));
        });
      }
      modal.classList.remove('hidden');
    };

    const bulkActionButtons = toolbar?.querySelectorAll('[data-bulk-action]');
    bulkActionButtons?.forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        if (selectedMembers.size === 0) {
          showBulkMessage('Select members before running a bulk action.');
          return;
        }
        openBulkModal(button.dataset.bulkAction);
      });
    });

    const formatBulkSummary = (payload) => {
      const applied = payload?.applied ?? 0;
      const skipped = payload?.skipped ?? [];
      let message = `Applied to ${applied} member${applied === 1 ? '' : 's'}.`;
      if (skipped.length > 0) {
        const reasons = skipped.map((entry) => entry.reason ?? 'Skipped').join('; ');
        message += ` Skipped ${skipped.length}: ${reasons}.`;
      }
      return message;
    };

    const executeBulkAction = async (actionKey, extras = {}) => {
      if (!csrfToken) {
        throw new Error('Missing CSRF token.');
      }
      const form = new FormData();
      form.append('csrf_token', csrfToken);
      form.append('action', 'bulk_member_action');
      form.append('bulk_action', actionKey);
      selectedMembers.forEach((id) => {
        form.append('member_ids[]', id);
      });
      Object.entries(extras).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          form.append(key, value);
        }
      });
      const response = await fetch('/admin/members/actions.php', {
        method: 'POST',
        body: form,
      });
      const payload = await parseJsonResponse(response);
      if (!response.ok) {
        throw new Error(payload.error || 'Bulk action failed.');
      }
      if (!payload.success) {
        throw new Error(payload.error || 'Bulk action failed.');
      }
      return payload;
    };

      modalForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const actionKey = modal?.dataset.actionKey;
        const definition = actionKey ? bulkActionDefinitions[actionKey] : null;
      if (!actionKey || !definition) {
        return;
      }
      const formValues = {};
      let valid = true;
      (definition.fields || []).forEach((field) => {
        const input = modalForm.querySelector(`[name="${field.name}"]`);
        const value = input?.value ?? '';
        if (field.required && value.trim() === '') {
          const hint = modalForm.querySelector(`[data-field-hint="${field.name}"]`);
          if (hint) {
            hint.textContent = field.invalidMessage || 'This field is required.';
            hint.classList.remove('hidden');
          }
          valid = false;
        } else if (field.validate && !field.validate(value)) {
          const hint = modalForm.querySelector(`[data-field-hint="${field.name}"]`);
          if (hint) {
            hint.textContent = field.invalidMessage || 'Invalid value.';
            hint.classList.remove('hidden');
          }
          valid = false;
        } else {
          const hint = modalForm.querySelector(`[data-field-hint="${field.name}"]`);
          hint?.classList.add('hidden');
          formValues[field.name] = value.trim();
        }
      });
      if (!valid) {
        return;
      }
      modalSubmit?.setAttribute('disabled', 'disabled');
      try {
        const payload = await executeBulkAction(actionKey, formValues);
        const tagline = formatBulkSummary(payload);
        showBulkMessage(tagline, payload.skipped?.length ? 'text-rose-500' : 'text-gray-600');
        selectedMembers.clear();
        rowCheckboxes.forEach((checkbox) => {
          checkbox.checked = false;
        });
        updateToolbar();
        closeModal();
        window.setTimeout(() => {
          window.location.reload();
        }, 800);
      } catch (error) {
        showBulkMessage(error.message || 'Bulk action failed.', 'text-rose-500');
      } finally {
        modalSubmit?.removeAttribute('disabled');
      }
    });

    updateToolbar();
  });
})();
