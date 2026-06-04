<div class="modal-bg" id="modal" role="dialog" aria-modal="true">
  <div class="modal">
    <div class="modal-title">🔒 باز کردن پیام</div>
    <div class="modal-err" id="modal-err">رمز اشتباه است. دوباره امتحان کنید.</div>
    <input type="password" id="modal-inp" placeholder="رمز پیام را وارد کنید" autocomplete="current-password">
    <div class="modal-btns">
      <button class="btn-cancel" onclick="closeModal()">انصراف</button>
      <button class="btn-ok" onclick="unlock()">باز کردن</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="group-modal" role="dialog" aria-modal="true">
  <div class="modal">
    <div class="modal-title">👥 ایجاد گروه جدید</div>
    <input type="text" id="group-name-inp" placeholder="نام گروه" maxlength="50">
    <div class="modal-btns">
      <button class="btn-cancel" onclick="closeGroupModal()">انصراف</button>
      <button class="btn-ok" onclick="createGroup()">ایجاد</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="member-modal" role="dialog" aria-modal="true">
  <div class="modal">
    <div class="modal-title">➕ افزودن عضو به گروه</div>
    <input type="text" id="member-id-inp" placeholder="شناسه کاربری ۸ رقمی" maxlength="8">
    <input type="hidden" id="member-group-id">
    <div class="modal-btns">
      <button class="btn-cancel" onclick="closeMemberModal()">انصراف</button>
      <button class="btn-ok" onclick="addMember()">افزودن</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="blocked-modal" role="dialog" aria-modal="true">
  <div class="modal modal-wide">
    <div class="modal-title">📋 کاربران بلاک‌شده</div>
    <div id="blocked-list" class="blocked-list">
      <div class="blocked-empty">لیست بلاک خالی است</div>
    </div>
    <div class="modal-btns">
      <button class="btn-cancel" onclick="closeBlockedModal()">بستن</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="profile-modal" role="dialog" aria-modal="true">
  <div class="modal">
    <div class="modal-title">👤 تنظیمات پروفایل</div>
    <input type="text" id="profile-inp" placeholder="نام نمایشی" maxlength="30">
    <div class="modal-btns">
      <button class="btn-cancel" onclick="closeProfileModal()">انصراف</button>
      <button class="btn-ok" onclick="saveProfile()">ذخیره</button>
    </div>
  </div>
</div>
