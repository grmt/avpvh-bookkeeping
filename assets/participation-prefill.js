document.addEventListener('DOMContentLoaded', function () {
    var memberId = new URLSearchParams(window.location.search).get('member_id');
    var memberSelect = document.querySelector('select[name="member_id"]');
    if (memberId && memberSelect && memberSelect.querySelector('option[value="' + CSS.escape(memberId) + '"]')) {
        memberSelect.value = memberId;
    }
});
