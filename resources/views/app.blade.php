<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#004d38">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="{{ url('manifest.json') }}">
<base href="/">
<title>SafeChat v{{ $version }}</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body>

@if (!$deviceId)
<div id="landing">
  <div style="font-size:72px;margin-bottom:20px;line-height:1;">🔒</div>
  <h1>SafeChat</h1>
  <p class="sub">
    پیام‌رسان امن با رمزنگاری سرتاسر.<br>همه پیام‌ها قبل از ذخیره رمزگذاری می‌شوند.
  </p>
  <div class="chips">
    <span class="chip">🔒 AES-256 / RSA</span>
    <span class="chip">🌐 چت عمومی</span>
    <span class="chip">🔐 پیام قفل‌دار</span>
    <span class="chip">👥 گروه امن</span>
  </div>
  <a href="{{ url('/chat') }}" class="start-btn">🚀 شروع چت</a>
</div>
@endif

<div id="app" @if(!$deviceId) style="display:none" @endif>
  <div id="overlay" onclick="closeSb()"></div>

  <aside id="sidebar">
    <div class="sb-top">
      <div class="sb-logo">
        <div class="sb-icon">🔒</div>
        <span class="sb-name">SafeChat</span>
      </div>
      <button id="sb-close" onclick="closeSb()" aria-label="بستن">✕</button>
    </div>

    <div class="id-card">
      <div class="id-label">شناسهٔ شما</div>
      <div class="id-row">
        <span class="id-val">{{ $deviceId ?? '' }}</span>
        <button class="btn-copy" id="btn-copy" onclick="copyId()">📋 کپی</button>
      </div>
    </div>

    <div class="sb-connect">
      <div class="sb-lbl">اتصال به کاربر</div>
      <div class="crow">
        <input id="inp-connect" type="text" maxlength="8" placeholder="شناسه ۸ کاراکتری" autocomplete="off" spellcheck="false">
        <button class="btn-go" onclick="connect()">اتصال</button>
      </div>
    </div>

    <div class="sb-tabs" role="tablist">
      <button class="tab on" data-t="pub" onclick="switchTab('pub')" role="tab">🌐 عمومی</button>
      <button class="tab" data-t="priv" onclick="switchTab('priv')" role="tab">🔒 خصوصی</button>
      <button class="tab" data-t="group" onclick="switchTab('group')" role="tab">👥 گروه‌ها</button>
    </div>

    <div id="conv-list">
      <div id="tab-pub">
        <div class="row on" onclick="openPublic();closeSb()">
          <div class="av pub">🌐</div>
          <div class="ri"><div class="rn">چت عمومی</div><div class="rs">همه کاربران</div></div>
        </div>
      </div>
      <div id="tab-priv" style="display:none">
        <div id="priv-list"></div>
        <div id="no-convs" class="no-convs">هنوز مکالمهٔ خصوصی ندارید.<br>با وارد کردن شناسه شروع کنید.</div>
      </div>
      <div id="tab-group" style="display:none">
        <div id="group-list"></div>
        <div id="no-groups" class="no-convs" style="display:none">هنوز گروهی ندارید.</div>
        @if($isAdmin)
        <div style="padding:8px 14px"><button class="btn-go block" onclick="showCreateGroupModal()">➕ ایجاد گروه</button></div>
        @endif
      </div>
    </div>

    @if($isAdmin)
    <div class="admin-area"><button class="btn-reset" onclick="resetDatabase()">💣 ریست دیتابیس</button></div>
    @endif
  </aside>

  <main id="chat">
    <div id="welcome">
      <div class="wic">🔒</div>
      <div class="wtitle">SafeChat</div>
      <div class="wdesc">پیام‌رسان امن با رمزنگاری سرتاسر.<br>همه پیام‌ها قبل از ذخیره رمزگذاری می‌شوند.</div>
      <div class="chips">
        <span class="chip">🔒 AES-256 / RSA</span>
        <span class="chip">🌐 چت عمومی</span>
        <span class="chip">🔐 پیام قفل‌دار</span>
        <span class="chip">👥 گروه</span>
      </div>
    </div>

    <header id="chat-hdr">
      <button class="menu-btn" onclick="openSb()" aria-label="منو">☰</button>
      <div class="hdr-av" id="hdr-av">🌐</div>
      <div class="hdr-info">
        <div class="hdr-name" id="hdr-name">چت عمومی</div>
        <div class="hdr-sub" id="hdr-sub">همه کاربران</div>
      </div>
      <div class="hdr-actions">
        <button id="btn-actions" type="button" class="menu-btn" title="تنظیمات" style="display:flex" aria-expanded="false" aria-controls="settings-panel">⚙️</button>
        <div class="settings-panel" id="settings-panel">
          <h4>تنظیمات</h4>
          <label><input type="checkbox" onchange="toggleTheme()"> 🌙 حالت تاریک</label>
          <label onclick="showProfileModal()">👤 پروفایل من</label>
          @if($deviceId)
          <label onclick="exportChat('json')">📥 خروجی JSON</label>
          <label onclick="exportChat('txt')">📥 خروجی TXT</label>
          <label id="block-action-label" style="display:none">🚫 بلاک کاربر</label>
          <label onclick="showBlockedModal()">📋 کاربران بلاک‌شده</label>
          @endif
        </div>
        <div class="live" id="live-indicator"><div class="ldot"></div>زنده</div>
        <button id="btn-add-member" type="button" class="btn-add-member" style="display:none">➕ افزودن عضو</button>
      </div>
    </header>

    <div class="search-bar" id="search-bar">
      <input type="search" id="search-inp" placeholder="جستجو در پیام‌های عمومی…" autocomplete="off" spellcheck="false"
        oninput="onSearchInput(this.value)"
        onkeydown="if(event.key==='Enter'){event.preventDefault();runSearch(this.value)}if(event.key==='Escape')exitSearch()">
      <button type="button" onclick="exitSearch()" title="بازگشت به چت" aria-label="بستن جستجو">✕</button>
    </div>

    <div id="block-banner" class="block-banner" style="display:none">
      <span id="block-banner-text"></span>
      <button type="button" id="block-banner-btn" onclick="unblockCurrentUser()">آنبلاک</button>
    </div>

    <div id="msgs"><div id="empty"><span>💬</span><p>هنوز پیامی وجود ندارد</p></div></div>
    <div id="typing-indicator">یکی در حال تایپ است...</div>

    <div id="reply-preview" class="reply-preview">
      <span>↩</span><span class="rptxt" id="reply-text"></span><button onclick="cancelReply()">✕</button>
    </div>

    <div id="edit-preview" class="reply-preview edit-preview">
      <span>✏️</span><span class="rptxt" id="edit-text">ویرایش پیام</span><button onclick="cancelEdit()">✕</button>
    </div>

    <div id="input-bar">
      <div id="pass-row"><span class="pass-lbl">🔒 رمز:</span><input id="inp-pass" type="password" placeholder="رمز پیام" maxlength="50" autocomplete="new-password"></div>
      <div class="irow">
        <div class="pill">
          <textarea id="inp-msg" rows="1" placeholder="پیام بنویسید..." maxlength="2000" aria-label="پیام"></textarea>
          <div class="pill-ac">
            <span id="char-ct"></span>
            <button class="btn-lock" id="btn-lock" onclick="toggleLock()" title="قفل با رمز">🔓</button>
          </div>
        </div>
        <button class="btn-send" id="btn-send" onclick="send()" aria-label="ارسال">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
        </button>
      </div>
    </div>
  </main>
</div>

@include('partials.modals')

<div id="toast" role="status"></div>

<script>
'use strict';
window.SAFECHAT_BOOT = {
  me: @json($deviceId),
  csrf: @json($csrfToken),
  csrfField: @json(config('safechat.csrf_token_name')),
  lsKey: 'safechat_device_id',
  lsTheme: 'safechat_theme',
  isAdmin: {{ $isAdmin ? 'true' : 'false' }},
};
</script>
<script src="{{ asset('assets/js/app.js') }}"></script>
</body>
</html>
