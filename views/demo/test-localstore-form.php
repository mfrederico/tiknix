<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LocalStorage Test Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .avatar-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #dee2e6;
            background-color: #f8f9fa;
        }
        .avatar-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 3px dashed #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            color: #6c757d;
            font-size: 0.875rem;
        }
        .saved-data-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .saved-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.5);
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">LocalStorage Test Form</h4>
                    </div>
                    <div class="card-body">
                        <form id="testForm">
                            <!-- Avatar Upload -->
                            <div class="text-center mb-4">
                                <div id="avatarContainer" class="d-inline-block position-relative">
                                    <div id="avatarPlaceholder" class="avatar-placeholder">
                                        No Avatar
                                    </div>
                                    <img id="avatarPreview" class="avatar-preview d-none" alt="Avatar Preview">
                                </div>
                                <div class="mt-2">
                                    <label for="avatarInput" class="btn btn-outline-secondary btn-sm">
                                        Choose Avatar
                                    </label>
                                    <input type="file" id="avatarInput" accept="image/*" class="d-none">
                                    <button type="button" id="clearAvatar" class="btn btn-outline-danger btn-sm d-none">
                                        Clear
                                    </button>
                                </div>
                                <small class="text-muted d-block mt-1">JPG, PNG, GIF (max 2MB)</small>
                            </div>

                            <!-- Name Fields -->
                            <div class="mb-3">
                                <label for="firstName" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="firstName" required>
                            </div>
                            <div class="mb-3">
                                <label for="lastName" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="lastName" required>
                            </div>

                            <!-- Buttons -->
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    Save to LocalStorage
                                </button>
                                <button type="button" id="loadBtn" class="btn btn-success">
                                    Load from LocalStorage
                                </button>
                                <button type="button" id="clearBtn" class="btn btn-outline-danger">
                                    Clear All Data
                                </button>
                            </div>
                        </form>

                        <!-- Status Messages -->
                        <div id="statusMessage" class="alert mt-3 d-none"></div>
                    </div>
                </div>

                <!-- Saved Data Display -->
                <div id="savedDataCard" class="card shadow mt-4 d-none">
                    <div class="card-body saved-data-card">
                        <h5 class="card-title mb-3">Saved Data</h5>
                        <div class="d-flex align-items-center">
                            <img id="savedAvatar" class="saved-avatar me-3 d-none" alt="Saved Avatar">
                            <div id="savedAvatarPlaceholder" class="saved-avatar me-3 d-flex align-items-center justify-content-center bg-white bg-opacity-25">
                                <span class="text-white-50">N/A</span>
                            </div>
                            <div>
                                <h5 class="mb-0" id="savedName">-</h5>
                                <small class="opacity-75" id="savedTimestamp">-</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Raw Data Debug -->
                <div class="card shadow mt-4">
                    <div class="card-header">
                        <h6 class="mb-0">Raw LocalStorage Data</h6>
                    </div>
                    <div class="card-body">
                        <pre id="rawData" class="bg-dark text-light p-3 rounded mb-0" style="max-height: 200px; overflow: auto; font-size: 0.75rem;">No data stored</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const STORAGE_KEY = 'tiknix_test_form_data';

        // DOM Elements
        const form = document.getElementById('testForm');
        const firstNameInput = document.getElementById('firstName');
        const lastNameInput = document.getElementById('lastName');
        const avatarInput = document.getElementById('avatarInput');
        const avatarPreview = document.getElementById('avatarPreview');
        const avatarPlaceholder = document.getElementById('avatarPlaceholder');
        const clearAvatarBtn = document.getElementById('clearAvatar');
        const loadBtn = document.getElementById('loadBtn');
        const clearBtn = document.getElementById('clearBtn');
        const statusMessage = document.getElementById('statusMessage');
        const savedDataCard = document.getElementById('savedDataCard');
        const savedAvatar = document.getElementById('savedAvatar');
        const savedAvatarPlaceholder = document.getElementById('savedAvatarPlaceholder');
        const savedName = document.getElementById('savedName');
        const savedTimestamp = document.getElementById('savedTimestamp');
        const rawData = document.getElementById('rawData');

        let currentAvatarBase64 = null;

        // Show status message
        function showStatus(message, type = 'success') {
            statusMessage.className = `alert alert-${type} mt-3`;
            statusMessage.textContent = message;
            statusMessage.classList.remove('d-none');
            setTimeout(() => statusMessage.classList.add('d-none'), 3000);
        }

        // Handle avatar file selection
        avatarInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            // Validate file size (2MB max)
            if (file.size > 2 * 1024 * 1024) {
                showStatus('File too large. Maximum size is 2MB.', 'danger');
                return;
            }

            // Validate file type
            if (!file.type.startsWith('image/')) {
                showStatus('Please select an image file.', 'danger');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(event) {
                currentAvatarBase64 = event.target.result;
                avatarPreview.src = currentAvatarBase64;
                avatarPreview.classList.remove('d-none');
                avatarPlaceholder.classList.add('d-none');
                clearAvatarBtn.classList.remove('d-none');
            };
            reader.onerror = function() {
                showStatus('Error reading file.', 'danger');
            };
            reader.readAsDataURL(file);
        });

        // Clear avatar
        clearAvatarBtn.addEventListener('click', function() {
            currentAvatarBase64 = null;
            avatarInput.value = '';
            avatarPreview.classList.add('d-none');
            avatarPlaceholder.classList.remove('d-none');
            clearAvatarBtn.classList.add('d-none');
        });

        // Save to localStorage
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const data = {
                firstName: firstNameInput.value.trim(),
                lastName: lastNameInput.value.trim(),
                avatar: currentAvatarBase64,
                savedAt: new Date().toISOString()
            };

            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
                showStatus('Data saved successfully!', 'success');
                updateRawDataDisplay();
                displaySavedData(data);
            } catch (error) {
                if (error.name === 'QuotaExceededError') {
                    showStatus('Storage quota exceeded. Try a smaller image.', 'danger');
                } else {
                    showStatus('Error saving data: ' + error.message, 'danger');
                }
            }
        });

        // Load from localStorage
        loadBtn.addEventListener('click', function() {
            const stored = localStorage.getItem(STORAGE_KEY);

            if (!stored) {
                showStatus('No saved data found.', 'warning');
                return;
            }

            try {
                const data = JSON.parse(stored);

                // Populate form fields
                firstNameInput.value = data.firstName || '';
                lastNameInput.value = data.lastName || '';

                // Restore avatar
                if (data.avatar) {
                    currentAvatarBase64 = data.avatar;
                    avatarPreview.src = data.avatar;
                    avatarPreview.classList.remove('d-none');
                    avatarPlaceholder.classList.add('d-none');
                    clearAvatarBtn.classList.remove('d-none');
                } else {
                    currentAvatarBase64 = null;
                    avatarPreview.classList.add('d-none');
                    avatarPlaceholder.classList.remove('d-none');
                    clearAvatarBtn.classList.add('d-none');
                }

                displaySavedData(data);
                showStatus('Data loaded successfully!', 'success');
            } catch (error) {
                showStatus('Error parsing saved data.', 'danger');
            }
        });

        // Clear all data
        clearBtn.addEventListener('click', function() {
            if (confirm('Are you sure you want to clear all saved data?')) {
                localStorage.removeItem(STORAGE_KEY);

                // Reset form
                form.reset();
                currentAvatarBase64 = null;
                avatarPreview.classList.add('d-none');
                avatarPlaceholder.classList.remove('d-none');
                clearAvatarBtn.classList.add('d-none');

                // Hide saved data card
                savedDataCard.classList.add('d-none');

                updateRawDataDisplay();
                showStatus('All data cleared.', 'info');
            }
        });

        // Display saved data in card
        function displaySavedData(data) {
            savedDataCard.classList.remove('d-none');
            savedName.textContent = `${data.firstName} ${data.lastName}`;
            savedTimestamp.textContent = `Saved: ${new Date(data.savedAt).toLocaleString()}`;

            if (data.avatar) {
                savedAvatar.src = data.avatar;
                savedAvatar.classList.remove('d-none');
                savedAvatarPlaceholder.classList.add('d-none');
            } else {
                savedAvatar.classList.add('d-none');
                savedAvatarPlaceholder.classList.remove('d-none');
            }
        }

        // Update raw data display
        function updateRawDataDisplay() {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                const data = JSON.parse(stored);
                // Truncate avatar for display
                const displayData = {
                    ...data,
                    avatar: data.avatar ? `[Base64 image: ${data.avatar.length} chars]` : null
                };
                rawData.textContent = JSON.stringify(displayData, null, 2);
            } else {
                rawData.textContent = 'No data stored';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateRawDataDisplay();

            // Auto-load if data exists
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                try {
                    const data = JSON.parse(stored);
                    displaySavedData(data);
                } catch (e) {
                    // Ignore parse errors
                }
            }
        });
    </script>
</body>
</html>
