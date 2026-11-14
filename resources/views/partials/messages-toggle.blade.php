{{-- Message icon for the top bar (both layouts), with the unread count. Opens and hides the Messages box. --}}
<button type="button" class="btn btn-secondary btn-small msg-toggle no-print" data-msg-toggle
        aria-controls="msgPanel" aria-expanded="false" aria-label="Messages" title="Messages">
    <svg class="msg-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
        <path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-4.2 3.2a.5.5 0 0 1-.8-.4V17H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"
              fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
    </svg>
    <span class="msg-badge" data-msg-badge hidden>0</span>
</button>
