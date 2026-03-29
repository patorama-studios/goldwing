<div class="fixed bottom-6 right-6 z-50 md:top-6 md:bottom-auto md:right-8">
  <div class="relative" id="feedback-widget-container">
    <button type="button" id="feedback-toggle-btn" class="bg-primary text-gray-900 px-5 py-2.5 rounded-full shadow-lg hover:bg-yellow-500 transition-colors flex items-center gap-2 font-semibold">
      <span class="material-icons-outlined text-sm">campaign</span> Beta Feedback
    </button>
    
    <div id="feedback-dropdown" class="absolute hidden bottom-full right-0 mb-3 md:bottom-auto md:top-full md:mt-3 w-80 bg-white rounded-xl shadow-2xl border border-gray-100 p-5 transition-all duration-200 opacity-0 transform scale-95 origin-bottom-right md:origin-top-right">
      <div class="flex justify-between items-center mb-2">
        <h3 class="font-bold text-gray-900 text-base">Send Feedback</h3>
        <button type="button" id="feedback-close-btn" class="text-gray-400 hover:text-gray-600 rounded-full p-1 hover:bg-gray-100 transition-colors"><span class="material-icons-outlined text-sm">close</span></button>
      </div>
      <p class="text-xs text-gray-500 mb-4">Found a bug or have a suggestion? Tell us about it below.</p>
      
      <div id="feedback-success" class="hidden bg-green-50 text-green-700 p-3 rounded-lg text-sm mb-2 border border-green-100 text-center font-medium shadow-sm">
        <span class="material-icons-outlined text-green-500 text-3xl block mb-2 mx-auto">check_circle</span>
        Thank you! Your feedback has been sent to our dev team.
      </div>
      <div id="feedback-error" class="hidden bg-red-50 text-red-700 p-3 rounded-lg text-sm mb-2">Something went wrong. Please try again.</div>

      <form id="feedback-form">
        <textarea id="feedback-message" required class="w-full text-sm border-gray-200 rounded-lg shadow-sm focus:border-primary focus:ring-primary mb-3 p-3 h-28 resize-none" placeholder="Describe the issue or feature request..."></textarea>
        <div class="flex justify-end gap-2">
          <button type="button" id="feedback-cancel-btn" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 rounded-lg transition-colors">Cancel</button>
          <button type="submit" id="feedback-submit-btn" class="px-4 py-2 text-sm bg-primary text-gray-900 font-semibold rounded-lg shadow-sm hover:bg-yellow-500 transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
            <span id="feedback-submit-text">Send</span>
            <span id="feedback-loading-icon" class="material-icons-outlined animate-spin text-sm hidden">refresh</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('feedback-widget-container');
    const toggleBtn = document.getElementById('feedback-toggle-btn');
    const closeBtn = document.getElementById('feedback-close-btn');
    const cancelBtn = document.getElementById('feedback-cancel-btn');
    const dropdown = document.getElementById('feedback-dropdown');
    const form = document.getElementById('feedback-form');
    const messageInput = document.getElementById('feedback-message');
    const submitBtn = document.getElementById('feedback-submit-btn');
    const submitText = document.getElementById('feedback-submit-text');
    const loadingIcon = document.getElementById('feedback-loading-icon');
    const successMsg = document.getElementById('feedback-success');
    const errorMsg = document.getElementById('feedback-error');

    let isOpen = false;

    function openDropdown() {
        isOpen = true;
        dropdown.classList.remove('hidden');
        // minor delay for CSS transition
        setTimeout(() => {
            dropdown.classList.remove('opacity-0', 'scale-95');
            dropdown.classList.add('opacity-100', 'scale-100');
        }, 10);
        messageInput.focus();
    }

    function closeDropdown() {
        isOpen = false;
        dropdown.classList.remove('opacity-100', 'scale-100');
        dropdown.classList.add('opacity-0', 'scale-95');
        setTimeout(() => {
            dropdown.classList.add('hidden');
            successMsg.classList.add('hidden');
            errorMsg.classList.add('hidden');
            form.classList.remove('hidden');
            messageInput.value = '';
        }, 200);
    }

    toggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (isOpen) closeDropdown();
        else openDropdown();
    });

    closeBtn.addEventListener('click', closeDropdown);
    cancelBtn.addEventListener('click', closeDropdown);

    document.addEventListener('click', (e) => {
        if (isOpen && !container.contains(e.target)) {
            closeDropdown();
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const message = messageInput.value.trim();
        if (!message) return;

        submitBtn.disabled = true;
        submitText.classList.add('hidden');
        loadingIcon.classList.remove('hidden');
        errorMsg.classList.add('hidden');
        successMsg.classList.add('hidden');

        try {
            const res = await fetch('/api/feedback.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            });
            const data = await res.json();
            
            if (data.success) {
                successMsg.classList.remove('hidden');
                form.classList.add('hidden');
                setTimeout(closeDropdown, 3500);
            } else {
                errorMsg.classList.remove('hidden');
                errorMsg.textContent = data.error || 'Something went wrong. Please try again.';
            }
        } catch (err) {
            errorMsg.classList.remove('hidden');
            errorMsg.textContent = 'Network error. Please try again.';
        } finally {
            submitBtn.disabled = false;
            submitText.classList.remove('hidden');
            loadingIcon.classList.add('hidden');
        }
    });
});
</script>
