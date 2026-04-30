// Function to toggle department card selection
function toggleDeptSelection(checkbox) {
    const card = checkbox.parentElement;
    if (checkbox.checked) {
        card.classList.add('selected');
    } else {
        card.classList.remove('selected');
    }
}

// Function for monitor.php to poll for new notifications
function checkNotifications() {
    fetch('get_notifications.php')
        .then(response => response.json())
        .then(data => {
            const popupContainer = document.getElementById('popup-container');
            popupContainer.innerHTML = ''; // Clear existing popups
            if (data.length > 0) {
                data.forEach(notification => {
                    const popup = document.createElement('div');
                    popup.className = 'popup';
                    popup.innerHTML = `
                        <h3>Token Called</h3>
                        <p>Token: ${notification.token_number}</p>
                        <p>Patient: ${notification.pat_name}</p>
                        <button onclick="closePopup(this)">Close</button>
                    `;
                    popupContainer.appendChild(popup);
                    // Auto-close after 10 seconds
                    setTimeout(() => {
                        popup.remove();
                    }, 10000);
                });
            }
        })
        .catch(error => console.error('Error fetching notifications:', error));
}

// Function to close popup manually
function closePopup(button) {
    button.parentElement.remove();
}

// Inline countdown timer for department_screen.php (count up from 0)
function startInlineCountdown(tokenId) {
    const countdownElement = document.getElementById(`countdown-${tokenId}`);
    if (!countdownElement) return;
    
    let timeElapsed = 0;
    countdownElement.textContent = formatTime(timeElapsed);

    const countdownInterval = setInterval(() => {
        timeElapsed++;
        if (countdownElement) {
            countdownElement.textContent = formatTime(timeElapsed);
        }
    }, 1000);

    // Store elapsed time when completed
    const completeForm = document.getElementById(`complete-form-${tokenId}`);
    if (completeForm) {
        completeForm.addEventListener('submit', () => {
            clearInterval(countdownInterval);
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'countdown_duration';
            hiddenInput.value = timeElapsed;
            completeForm.appendChild(hiddenInput);
        });
    }
}

// Format time in MM:SS
function formatTime(seconds) {
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

// Start polling for monitor.php
if (document.getElementById('popup-container')) {
    setInterval(checkNotifications, 5000);
    checkNotifications();
}