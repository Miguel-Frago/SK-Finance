{{--
    Messages box. It opens IN FRONT of the page (just under the top bar), so nothing on the page moves or shrinks.
    Included once from each layout (barangay and Admin), after .main. public/js/messages-app.js fills it in.
    The two sign-in systems use different addresses, chosen here from the page: /messages/* and /admin/messages/*.
--}}
@php($msgAdminPage = request()->routeIs('admin.*'))
<aside class="msg-panel no-print" id="msgPanel" aria-labelledby="msgTitle" aria-hidden="true" inert
       data-base="{{ $msgAdminPage ? url('/admin/messages') : url('/messages') }}"
       data-admin="{{ $msgAdminPage ? '1' : '0' }}"
       data-max-mb="{{ rtrim(rtrim(number_format((float) config('messaging.max_file_mb'), 1, '.', ''), '0'), '.') }}"
       data-max-files="{{ (int) config('messaging.max_files') }}"
       data-blocked="{{ json_encode(array_values((array) config('messaging.blocked_extensions'))) }}">
    <div class="msg-panel-inner">
        <header class="msg-head">
            <button type="button" class="msg-back" id="msgBack" aria-label="Back to all conversations" hidden>
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="msg-head-text">
                <h2 id="msgTitle">Messages</h2>
                <p class="msg-sub" id="msgSub" hidden></p>
            </div>
            <button type="button" class="msg-hide" id="msgInfo" hidden title="Who is in this group">Members</button>
            <button type="button" class="msg-hide" id="msgHide" hidden title="Hide this chat from my list">Hide</button>
            <button type="button" class="msg-close" data-msg-close aria-label="Hide the messages box">&times;</button>
        </header>

        {{-- the list of chats, and the search for a new one --}}
        <section class="msg-view" id="msgListView">
            <div class="msg-search">
                <label class="msg-sr" for="msgFind">Find a person to message</label>
                <input type="search" id="msgFind" placeholder="Find a person to message" autocomplete="off" maxlength="60">
            </div>
            <div class="msg-scroll" id="msgList" tabindex="0" aria-label="Conversations"></div>

            {{-- footer: All chats | Group (the Group tab lists the group chats, and has the "New group" button for the Admin and the Chairmen) --}}
            <nav class="msg-tabs" id="msgTabs" aria-label="Kind of chat">
                <button type="button" class="msg-tab is-active" id="msgTabAll" aria-pressed="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.5-4.6A8 8 0 1 1 21 12z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    All chats
                </button>
                <button type="button" class="msg-tab" id="msgTabGroup" aria-pressed="false">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 19c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="9" r="2.4"/><path d="M17.5 13.7c2.3.3 3.5 2 3.5 4.3"/></g></svg>
                    Group
                    <span class="msg-tab-count" id="msgTabGroupCount" hidden>0</span>
                </button>
            </nav>
        </section>

        {{-- one chat --}}
        <section class="msg-view" id="msgThreadView" hidden>
            <div class="msg-scroll msg-thread" id="msgThread" tabindex="0" role="log" aria-label="Messages in this conversation"></div>
            <button type="button" class="msg-jump" id="msgJump" hidden>New messages &darr;</button>
            <p class="msg-note" id="msgNote" role="status" hidden></p>
            <div class="msg-chips" id="msgChips" hidden></div>
            <form class="msg-compose" id="msgCompose" autocomplete="off">
                <button type="button" class="msg-attach" id="msgAttach" aria-label="Attach files" title="Attach files (photos, videos, documents)">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M21 11.5l-8.6 8.6a5 5 0 0 1-7.1-7.1l8.9-8.9a3.3 3.3 0 0 1 4.7 4.7l-8.9 8.9a1.7 1.7 0 0 1-2.4-2.4l8.2-8.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <input type="file" id="msgFile" multiple hidden>
                <label class="msg-sr" for="msgInput">Write a message</label>
                <textarea id="msgInput" rows="1" maxlength="2000" placeholder="Write a message"></textarea>
                <button type="submit" class="btn btn-primary btn-small" id="msgSend">Send</button>
            </form>
        </section>

        {{-- a new group (the Admin and the Chairmen) --}}
        <section class="msg-view msg-form-view" id="msgGroupView" hidden>
            <form class="msg-form msg-scroll" id="msgGroupForm" autocomplete="off">
                <label class="msg-label" for="msgGroupTitle">Group name</label>
                <input type="text" id="msgGroupTitle" class="msg-input" maxlength="60" placeholder="For example: Barangay council">

                <label class="msg-label" for="msgGroupFind">Add people</label>
                <input type="search" id="msgGroupFind" class="msg-input" maxlength="60" placeholder="Search a name, position or barangay">

                <div class="msg-chosen" id="msgGroupChosen" aria-live="polite"></div>
                <div class="msg-pick" id="msgGroupResults"></div>
                <p class="msg-note is-inline" id="msgGroupNote" role="status" hidden></p>
                <button type="submit" class="btn btn-primary" id="msgGroupCreate" disabled>Create group</button>
            </form>
        </section>

        {{-- the people in a group --}}
        <section class="msg-view msg-form-view" id="msgMembersView" hidden>
            <div class="msg-form msg-scroll" id="msgMembersBody" tabindex="0" aria-label="Members of this group"></div>
        </section>
    </div>
    <span class="msg-sr" id="msgLive" aria-live="polite"></span>
</aside>
