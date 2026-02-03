<div class="onboarding-card">
    <div class="onboarding-header">
        <h1>Create New Project</h1>
        <p>Deploy your application to the cloud.</p>
    </div>
    
    <form action="projects/submit" method="post">
        <div class="form-group">
            <label class="form-label">Project Name</label>
            <input type="text" name="label" required placeholder="My Awesome App" class="form-input">
        </div>
        
        <div class="form-group">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
                <option value="web">Web Application</option>
                <option value="worker">Worker</option>
            </select>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn-primary" style="flex: 1;">Create Project</button>
                <a href="customer" class="btn-secondary" style="flex: 1; text-align: center;">Cancel</a>
        </div>
    </form>
</div>
