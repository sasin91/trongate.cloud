
function openDeployModal() {
    const dialog = document.getElementById('deployModal');
    if (dialog) {
        dialog.showModal();
    }
}

function closeDeployModal() {
    const dialog = document.getElementById('deployModal');
    if (dialog) {
        dialog.close();
    }
}

// Close dialog when clicking on backdrop
document.addEventListener('click', (event) => {
    const dialog = document.getElementById('deployModal');
    if (dialog && event.target === dialog) {
        dialog.close();
    }
});
