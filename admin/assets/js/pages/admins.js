function admOpenReset(id, name) {
    document.getElementById('adm-reset-id').value = String(id);
    document.getElementById('adm-reset-name').value = name;
    openAdminModal('adm-reset-modal');
}
