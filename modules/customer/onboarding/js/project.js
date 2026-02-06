document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('repository').addEventListener('change', function () {
        const labelInput = document.getElementById('label');
        if (labelInput.value.trim() === '') {
            const repoValue = this.value;
            if (repoValue) {
                // Extract repo name from "owner/repo"
                const repoName = repoValue.split('/').pop();
                labelInput.value = repoName;
            }
        }
    });
});