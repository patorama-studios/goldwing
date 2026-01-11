(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const modal = document.querySelector('[data-associate-modal]');
    const openButton = document.querySelector('[data-associate-open]');
    if (!modal || !openButton) {
      return;
    }

    const closeButton = modal.querySelector('[data-associate-close]');
    const searchForm = modal.querySelector('[data-associate-search-form]');
    const searchInput = searchForm?.querySelector('input[name="associate_query"]');
    const searchButton = searchForm?.querySelector('button[type="submit"]');
    const resultsContainer = modal.querySelector('[data-associate-results]');
    const feedbackEl = modal.querySelector('[data-associate-feedback]');
    const configEl = document.querySelector('[data-associate-config]');
    const csrfToken = configEl?.dataset.csrfToken || '';
    const memberId = configEl?.dataset.memberId || '';
    const endpoint = '/admin/members/actions.php';
    let isBusy = false;

    const parseJsonResponse = async (response) => {
      const text = await response.text();
      try {
        return JSON.parse(text);
      } catch (error) {
        console.error('Associate modal response parse error', text);
        throw new Error('Server response could not be parsed.');
      }
    };

    const showFeedback = (message, tone = 'text-gray-500') => {
      if (!feedbackEl) {
        return;
      }
      feedbackEl.textContent = message;
      feedbackEl.className = `mt-1 text-sm ${tone}`;
    };

    const resetResults = () => {
      if (resultsContainer) {
        resultsContainer.innerHTML = '';
      }
    };

    const closeModal = () => {
      modal.classList.add('hidden');
      resetResults();
      searchInput?.removeAttribute('disabled');
      searchButton?.removeAttribute('disabled');
      showFeedback(' ');
      if (searchInput) {
        searchInput.value = '';
      }
    };

    const setBusy = (value) => {
      isBusy = Boolean(value);
      if (searchInput) {
        if (isBusy) {
          searchInput.setAttribute('disabled', 'disabled');
        } else {
          searchInput.removeAttribute('disabled');
        }
      }
      if (searchButton) {
        if (isBusy) {
          searchButton.setAttribute('disabled', 'disabled');
        } else {
          searchButton.removeAttribute('disabled');
        }
      }
    };

    const linkAssociate = async (associateId) => {
      if (!csrfToken || !memberId) {
        showFeedback('Missing CSRF configuration.', 'text-rose-500');
        return;
      }
      setBusy(true);
      try {
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('action', 'link_associate_member');
        form.append('member_id', memberId);
        form.append('associate_member_id', associateId.toString());
        const response = await fetch(endpoint, {
          method: 'POST',
          body: form,
        });
        const payload = await parseJsonResponse(response);
        if (!response.ok) {
          throw new Error(payload.error || 'Unable to link associate.');
        }
        if (!payload.success) {
          throw new Error(payload.error || 'Unable to link associate.');
        }
        showFeedback(payload.message || 'Associate linked. Reloading…', 'text-emerald-600');
        window.setTimeout(() => window.location.reload(), 700);
      } catch (error) {
        showFeedback(error.message || 'Link failed.', 'text-rose-500');
      } finally {
        setBusy(false);
      }
    };

    const renderResults = (items) => {
      if (!resultsContainer) {
        return;
      }
      resultsContainer.innerHTML = '';
      if (!items || items.length === 0) {
        resultsContainer.innerHTML = '<p class="text-sm text-gray-500">No matches found.</p>';
        return;
      }
      items.forEach((item) => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between rounded-2xl border border-gray-100 bg-gray-50 px-3 py-2';
        const info = document.createElement('div');
        const displayName = item.display_name || item.name || 'Associate member';
        info.innerHTML = `
          <p class="font-semibold text-gray-900">${displayName}</p>
          <div class="text-xs text-gray-500">
            ${item.member_number ? `<span>Member #${item.member_number}</span>` : ''}
            ${item.email ? `<span class="ml-3">${item.email}</span>` : ''}
          </div>
        `;
        const action = document.createElement('button');
        action.type = 'button';
        action.className = 'inline-flex items-center rounded-full border border-primary px-4 py-1 text-xs font-semibold text-primary hover:bg-primary/10';
        action.textContent = 'Link';
        action.addEventListener('click', () => linkAssociate(item.id));
        row.appendChild(info);
        row.appendChild(action);
        resultsContainer.appendChild(row);
      });
    };

    const unlinkAssociate = async (associateId, triggerButton) => {
      if (!csrfToken || !memberId) {
        showFeedback('Missing configuration.', 'text-rose-500');
        return;
      }
      setBusy(true);
      if (triggerButton) {
        triggerButton.disabled = true;
      }
      try {
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('action', 'unlink_associate_member');
        form.append('member_id', memberId);
        form.append('associate_member_id', associateId.toString());
        const response = await fetch(endpoint, {
          method: 'POST',
          body: form,
        });
        const payload = await parseJsonResponse(response);
        if (!response.ok || !payload.success) {
          throw new Error(payload.error || 'Unable to unlink associate.');
        }
        showFeedback(payload.message || 'Associate unlinked. Reloading…', 'text-emerald-600');
        window.setTimeout(() => window.location.reload(), 700);
      } catch (error) {
        showFeedback(error.message || 'Unable to unlink associate.', 'text-rose-500');
      } finally {
        if (triggerButton) {
          triggerButton.disabled = false;
        }
        setBusy(false);
      }
    };

    const attachUnlinkHandlers = () => {
      const unlinkButtons = document.querySelectorAll('[data-associate-unlink]');
      unlinkButtons.forEach((button) => {
        button.addEventListener('click', () => {
          if (isBusy) {
            return;
          }
          const associateId = button.dataset.associateId;
          if (!associateId) {
            return;
          }
          if (!confirm('Remove this associate from the member?')) {
            return;
          }
          unlinkAssociate(associateId, button);
        });
      });
    };
    attachUnlinkHandlers();

    const performSearch = async (query) => {
      if (!query) {
        showFeedback('Enter a name or member number to search.', 'text-gray-500');
        resetResults();
        return;
      }
      if (!csrfToken || !memberId) {
        showFeedback('Missing configuration.', 'text-rose-500');
        return;
      }
      setBusy(true);
      showFeedback('Searching…', 'text-gray-500');
      try {
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('action', 'associate_search');
        form.append('member_id', memberId);
        form.append('query', query);
        const response = await fetch(endpoint, {
          method: 'POST',
          body: form,
        });
        const payload = await parseJsonResponse(response);
        if (!response.ok || !payload.success) {
          throw new Error(payload.error || 'Search failed.');
        }
        if (payload.results && payload.results.length > 0) {
          renderResults(payload.results);
          showFeedback(`Found ${payload.results.length} candidate${payload.results.length === 1 ? '' : 's'}.`, 'text-gray-500');
        } else {
          resetResults();
          showFeedback('No matches found.', 'text-gray-500');
        }
      } catch (error) {
        showFeedback(error.message || 'Search failed.', 'text-rose-500');
        resetResults();
      } finally {
        setBusy(false);
      }
    };

    openButton.addEventListener('click', () => {
      modal.classList.remove('hidden');
      showFeedback(' ');
      searchInput?.focus();
    });

    closeButton?.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal();
      }
    });

    searchForm?.addEventListener('submit', (event) => {
      event.preventDefault();
      if (isBusy) {
        return;
      }
      performSearch(searchInput?.value?.trim() ?? '');
    });
  });
})();
