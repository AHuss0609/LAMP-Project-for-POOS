const registerForm = document.getElementById("registerForm");

if (registerForm) {
    registerForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        const firstName = document.getElementById("firstName").value;
        const lastName = document.getElementById("lastName").value;
        const username = document.getElementById("username").value;
        const password = document.getElementById("password").value;

        const registerMessage = document.getElementById("registerMessage");

        try {
            const response = await fetch(
                "/api/index.php/register",
                {
                    method: "POST",

                    headers: {
                        "Content-Type": "application/json"
                    },

                    body: JSON.stringify({
                        firstName: firstName,
                        lastName: lastName,
                        username: username,
                        password: password
                    })
                }
            );

            const data = await response.json();

            if (response.ok) {
                registerMessage.textContent = "Account created successfully!";

                setTimeout(function () {
                    window.location.href = "index.html";
                }, 1500);
            } else {
                registerMessage.textContent =
                    data.error || "Unable to create account.";
            }

        } catch (error) {
            console.error(error);

            registerMessage.textContent =
                "Unable to connect to the server.";
        }
    });
}

const loginForm = document.getElementById("loginForm");

if (loginForm) {
    loginForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        const username = document.getElementById("username").value;
        const password = document.getElementById("password").value;

        const loginError = document.getElementById("loginError");

        try {
            const credentials = btoa(username + ":" + password);

            const response = await fetch("/api/index.php/login", {
                method: "POST",

                headers: {
                    "Authorization": "Basic " + credentials
                }
            });

            const data = await response.json();

            if (response.ok) {
                sessionStorage.setItem("user", JSON.stringify({
                    ...data,
                    username: username,
                    password: password
                }));

                window.location.href = data.isAdmin
                    ? "admin.html"
                    : "contacts.html";
            } else {
                loginError.textContent = "Invalid login or disabled account. Please contact an administrator if you need help.";
            }

        } catch (error) {
            console.error(error);

            loginError.textContent = "Unable to connect to the server.";
        }
    });
}

const logoutButton = document.getElementById("logoutButton");

if (logoutButton) {
    logoutButton.addEventListener("click", function () {
        sessionStorage.removeItem("user");
        window.location.href = "index.html";
    });
}

const contactsList = document.getElementById("contactsList");

if (contactsList) {
    const user = JSON.parse(sessionStorage.getItem("user"));

    if (!user) {
        window.location.href = "index.html";
    } else {
        loadContacts(user);
    }
}

async function loadContacts(user , search = "") {
    try {
        const response = await fetch(
            "/api/index.php/contacts?q=" + encodeURIComponent(search) + "&limit=25&offset=0",
            {
                method: "GET",
                headers: {
                    "Authorization": "Basic " + btoa(user.username + ":" + user.password)
                }
            }
        );

        if (!response.ok) {
            throw new Error("Unable to load contacts");
        }

        const data = await response.json();

        console.log("Contacts returned:", data);

        contactsList.innerHTML = "";

        const contacts = data.contacts || data;

        if (!contacts || contacts.length === 0) {
            if (search) {
                contactsList.innerHTML = "<p>No matching contacts found.</p>";
            } else {
                contactsList.innerHTML = "<p>No contacts found.</p>";
            }

            return;
        }

        contacts.forEach(contact => {
            const contactElement = document.createElement("div");

            contactElement.classList.add("contact-card");
            contactElement.dataset.contact = JSON.stringify(contact);

            contactElement.innerHTML = `
                <h3>${contact.firstName} ${contact.lastName}</h3>

                <p>
                    ${contact.email || "No email"}
                </p>

                <p>
                    ${contact.phoneNumber || "No phone number"}
                </p>

                <div class="contact-actions">
                    <button class="edit-button" onclick="editContact(${contact.id}, this)">Edit</button>
                    <button class="delete-button" onclick="deleteContact(${contact.id})">Delete</button>
                </div>
            `;

            contactsList.appendChild(contactElement);
        });

    } catch (error) {
        console.error(error);
        contactsList.innerHTML = "<p>Unable to load contacts.</p>";
    }
}

const contactForm = document.getElementById("contactForm");

if (contactForm) {
    contactForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        const firstName = document.getElementById("contactFirstName").value;
        const lastName = document.getElementById("contactLastName").value;
        const email = document.getElementById("contactEmail").value;
        const phoneNumber = document.getElementById("contactPhone").value;

        const contactMessage = document.getElementById("contactMessage");
        const user = JSON.parse(sessionStorage.getItem("user"));

        try {
            const credentials = btoa(user.username + ":" + user.password);

            const response = await fetch("/api/index.php/contacts", {
                method: "POST",

                headers: {
                    "Content-Type": "application/json",
                    "Authorization": "Basic " + credentials
                },

                body: JSON.stringify({
                    firstName: firstName,
                    lastName: lastName,
                    email: email,
                    phoneNumber: phoneNumber
                })
            });

            const data = await response.json();

            if (response.ok) {
                contactMessage.textContent = "Contact added successfully!";

                contactForm.reset();

                // Reload the contacts so the new contact appears
                loadContacts(user);

                // Remove the success message after 3 seconds
                setTimeout(function () {
                    contactMessage.textContent = "";
                }, 3000);
            } else {
                contactMessage.textContent =
                    data.message || "Unable to add contact.";
            }

        } catch (error) {
            console.error(error);
            contactMessage.textContent = "Unable to connect to the server.";
        }
    });
}

async function deleteContact(contactId) {
    const user = JSON.parse(sessionStorage.getItem("user"));

    if (!user) {
        window.location.href = "index.html";
        return;
    }

    const confirmed = confirm("Are you sure you want to delete this contact?");

    if (!confirmed) {
        return;
    }

    try {
        const credentials = btoa(user.username + ":" + user.password);

        const response = await fetch(
            "/api/index.php/contacts/" + contactId,
            {
                method: "DELETE",
                headers: {
                    "Authorization": "Basic " + credentials
                }
            }
        );

        const data = await response.json();

        if (response.ok) {
            loadContacts(user);
        } else {
            alert(data.error || "Unable to delete contact.");
        }

    } catch (error) {
        console.error(error);
        alert("Unable to connect to the server.");
    }
}

function editContact(contactId, button) {
    const card = button.closest(".contact-card");

    const contacts = JSON.parse(card.dataset.contact);

    card.innerHTML = `
        <input
            type="text"
            class="edit-first-name"
            value="${contacts.firstName}"
            placeholder="First Name"
        >

        <input
            type="text"
            class="edit-last-name"
            value="${contacts.lastName}"
            placeholder="Last Name"
        >

        <input
            type="email"
            class="edit-email"
            value="${contacts.email || ""}"
            placeholder="Email"
        >

        <input
            type="text"
            class="edit-phone"
            value="${contacts.phoneNumber || ""}"
            placeholder="Phone Number"
        >

        <div class="contact-actions">
            <button class="edit-button"
                onclick="saveContact(${contactId}, this)">
                Save Changes
            </button>

            <button class="delete-button"
                onclick="cancelEdit()">
                Cancel
            </button>
        </div>
    `;
}

async function saveContact(contactId, button) {
    const card = button.closest(".contact-card");

    const firstName = card.querySelector(".edit-first-name").value;
    const lastName = card.querySelector(".edit-last-name").value;
    const email = card.querySelector(".edit-email").value;
    const phoneNumber = card.querySelector(".edit-phone").value;

    const user = JSON.parse(sessionStorage.getItem("user"));

    try {
        const credentials = btoa(user.username + ":" + user.password);

        const response = await fetch(
            "/api/index.php/contacts/" + contactId,
            {
                method: "PUT",

                headers: {
                    "Content-Type": "application/json",
                    "Authorization": "Basic " + credentials
                },

                body: JSON.stringify({
                    firstName: firstName,
                    lastName: lastName,
                    email: email,
                    phoneNumber: phoneNumber
                })
            }
        );

        const data = await response.json();

        if (response.ok) {
            loadContacts(user);
        } else {
            alert(data.error || "Unable to update contact.");
        }

    } catch (error) {
        console.error(error);
        alert("Unable to connect to the server.");
    }
}

function cancelEdit() {
    const user = JSON.parse(sessionStorage.getItem("user"));
    loadContacts(user);
}

const searchContacts = document.getElementById("searchContacts");

if (searchContacts) {
    searchContacts.addEventListener("input", function () {
        const user = JSON.parse(sessionStorage.getItem("user"));
        const search = searchContacts.value.trim();

        loadContacts(user, search);
    });
}

// ==============================
// ADMIN DASHBOARD
// ==============================

const adminUsersList = document.getElementById("adminUsersList");

if (adminUsersList) {

    const user = JSON.parse(sessionStorage.getItem("user"));

    // Make sure someone is logged in and is an admin
    if (!user || !user.isAdmin) {
        window.location.href = "index.html";
    } else {
        loadAdminUsers();
    }

    async function loadAdminUsers(searchQuery = "") {
        try {
            const credentials = btoa(user.username + ":" + user.password);

            const response = await fetch(`/api/index.php/admin/users?q=${encodeURIComponent(searchQuery)}&limit=100`,
            {
                method: "GET",
                headers: {
                    "Authorization": "Basic " + credentials
                }
            });

            const data = await response.json();

            if (!response.ok) {
                adminUsersList.innerHTML = "<p>Unable to load users.</p>";
                return;
            }

            displayAdminUsers(data.users);

        } catch (error) {
            console.error(error);
            adminUsersList.innerHTML = "<p>Unable to connect to the server.</p>";
        }
    }

    function displayAdminUsers(users) {
        adminUsersList.innerHTML = "";

        users.forEach(account => {
            const userCard = document.createElement("div");
            userCard.className = "admin-user-card";

            userCard.innerHTML = `
                <h3>${account.firstName} ${account.lastName}</h3>
                <p><strong>Username:</strong> ${account.username}</p>
                <div class="user-badges">
                    <span class="role-badge ${account.isAdmin ? "admin-badge" : "user-badge"}">
                        ${account.isAdmin ? "Admin" : "User"}
                    </span>

                    <span class="status-badge ${account.isActive ? "active-badge" : "disabled-badge"}">
                        ${account.isActive ? "Active" : "Disabled"}
                    </span>
                </div>

                <button class="manage-user-button" data-user-id="${account.id}">
                    Manage User
                </button>
            `;

            adminUsersList.appendChild(userCard);

            const manageButton = userCard.querySelector(".manage-user-button");

            manageButton.addEventListener("click", function () {

                // Remove selection from any previously selected user
                document.querySelectorAll(".admin-user-card").forEach(card => {
                    card.classList.remove("selected");
                });

                // Highlight this user
                userCard.classList.add("selected");

                showAdminControls(account);
            });
        });
    }

    function showAdminControls(account) {
        const selectedUser = document.getElementById("selectedUser");

        selectedUser.innerHTML = `
            <div class="selected-user-info">
                <h3>${account.firstName} ${account.lastName}</h3>

                <p><strong>Username:</strong> ${account.username}</p>

                <div class="user-badges">
                    <span class="role-badge ${account.isAdmin ? "admin-badge" : "user-badge"}">
                        ${account.isAdmin ? "Admin" : "User"}
                    </span>

                    <span class="status-badge ${account.isActive ? "active-badge" : "disabled-badge"}">
                        ${account.isActive ? "Active" : "Disabled"}
                    </span>
                </div>

                <hr>

                <h3>Change Password</h3>

                <input
                    type="password"
                    id="adminNewPassword"
                    placeholder="New Password"
                >

                <button id="changePasswordButton">
                    Change Password
                </button>

                <p id="adminMessage"></p>

                <h3>Account Status</h3>

                <button
                    id="changeStatusButton"
                    class="${account.isActive ? "disable-user-button" : "enable-user-button"}"
                >
                    ${account.isActive ? "Disable User" : "Enable User"}
                </button>
            </div>
        `;

        const changePasswordButton = document.getElementById("changePasswordButton");

        changePasswordButton.addEventListener("click", async function () {
            const newPassword = document.getElementById("adminNewPassword").value.trim();

            if (!newPassword) {
                document.getElementById("adminMessage").textContent =
                    "Please enter a new password.";
                return;
            }

            const user = JSON.parse(sessionStorage.getItem("user"));
            const credentials = btoa(user.username + ":" + user.password);

            try {
                const response = await fetch(`/api/index.php/admin/users/${account.id}/password`, {
                    method: "PUT",
                    headers: {
                        "Authorization": "Basic " + credentials,
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        password: newPassword
                    })
                });

                const data = await response.json();

                const adminMessage = document.getElementById("adminMessage");

                if (response.ok) {
                    adminMessage.textContent = "Password changed successfully.";
                    document.getElementById("adminNewPassword").value = "";
                } else {
                    adminMessage.textContent = data.error || "Unable to change password.";
                }

            } catch (error) {
                console.error(error);

                document.getElementById("adminMessage").textContent =
                    "Unable to connect to the server.";
            }
        });

        const changeStatusButton = document.getElementById("changeStatusButton");

        changeStatusButton.addEventListener("click", async function () {
            const user = JSON.parse(sessionStorage.getItem("user"));
            const credentials = btoa(user.username + ":" + user.password);

            // If active, disable them. If disabled, enable them.
            const newStatus = !account.isActive;

            try {
                const response = await fetch(
                    `/api/index.php/admin/users/${account.id}/status`,
                    {
                        method: "PUT",
                        headers: {
                            "Authorization": "Basic " + credentials,
                            "Content-Type": "application/json"
                        },
                        body: JSON.stringify({
                            isActive: newStatus
                        })
                    }
                );

                const data = await response.json();

                if (response.ok) {
                    document.getElementById("adminMessage").textContent =
                        newStatus
                            ? "User enabled successfully."
                            : "User disabled successfully.";

                    account.isActive = newStatus;

                    // Refresh the user list so the badge updates
                    loadAdminUsers();

                    // Refresh the controls so the button changes
                    showAdminControls(account);
                } else {
                    document.getElementById("adminMessage").textContent =
                        data.error || "Unable to update user status.";
                }

            } catch (error) {
                console.error(error);

                document.getElementById("adminMessage").textContent =
                    "Unable to connect to the server.";
            }
        });
    }

    const adminSearch = document.getElementById("adminSearch");

    if (adminSearch) {
        adminSearch.addEventListener("input", function () {
            const searchQuery = adminSearch.value.trim();
            loadAdminUsers(searchQuery);
        });
    }
}

// ==============================
// CREATE ADMIN
// ==============================

const createAdminForm = document.getElementById("createAdminForm");

if (createAdminForm) {
    createAdminForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        const firstName = document.getElementById("adminFirstName").value.trim();
        const lastName = document.getElementById("adminLastName").value.trim();
        const username = document.getElementById("adminUsername").value.trim();
        const password = document.getElementById("adminPassword").value;

        const message = document.getElementById("createAdminMessage");

        const user = JSON.parse(sessionStorage.getItem("user"));

        if (!user || !user.isAdmin) {
            message.textContent = "Administrator access required.";
            return;
        }

        try {
            const credentials = btoa(user.username + ":" + user.password);

            const response = await fetch("/api/index.php/admin/users", {
                method: "POST",

                headers: {
                    "Authorization": "Basic " + credentials,
                    "Content-Type": "application/json"
                },

                body: JSON.stringify({
                    firstName: firstName,
                    lastName: lastName,
                    username: username,
                    password: password
                })
            });

            const data = await response.json();

            if (response.ok) {
                message.textContent = "Admin account created successfully.";

                createAdminForm.reset();

                // Reload the page so the new admin appears in the user list
                setTimeout(function () {
                    window.location.reload();
                }, 1000);

            } else {
                message.textContent =
                    data.error || "Unable to create admin account.";
            }

        } catch (error) {
            console.error(error);

            message.textContent =
                "Unable to connect to the server.";
        }
    });
}

// ==============================
// ADMIN - ALL CONTACTS
// ==============================

const adminContactsList = document.getElementById("adminContactsList");
const adminContactSearch = document.getElementById("adminContactSearch");

if (adminContactsList) {

    async function loadAdminContacts(searchQuery = "") {

        const user = JSON.parse(sessionStorage.getItem("user"));

        if (!user || !user.isAdmin) {
            adminContactsList.innerHTML =
                "<p>Administrator access required.</p>";
            return;
        }

        const credentials = btoa(user.username + ":" + user.password);

        try {
            const response = await fetch(
                `/api/index.php/admin/contacts?q=${encodeURIComponent(searchQuery)}&limit=100`,
                {
                    method: "GET",
                    headers: {
                        "Authorization": "Basic " + credentials
                    }
                }
            );

            const data = await response.json();

            if (!response.ok) {
                adminContactsList.innerHTML =
                    `<p>${data.error || "Unable to load contacts."}</p>`;
                return;
            }

            displayAdminContacts(data.contacts);

        } catch (error) {
            console.error(error);

            adminContactsList.innerHTML =
                "<p>Unable to connect to the server.</p>";
        }
    }


    function displayAdminContacts(contacts) {

        adminContactsList.innerHTML = "";

        if (!contacts || contacts.length === 0) {
            adminContactsList.innerHTML =
                "<p>No contacts found.</p>";
            return;
        }

        contacts.forEach(contact => {

            const contactCard = document.createElement("div");
            contactCard.className = "admin-contact-card";

            contactCard.innerHTML = `
                <h3>${contact.firstName} ${contact.lastName}</h3>

                <p>
                    <strong>Email:</strong>
                    ${contact.email || "None"}
                </p>

                <p>
                    <strong>Phone:</strong>
                    ${contact.phoneNumber || "None"}
                </p>

                <span class="contact-owner">
                    Owner: ${contact.username}
                </span>
            `;

            adminContactsList.appendChild(contactCard);
        });
    }


    // Load all contacts when the Admin Dashboard opens
    loadAdminContacts();


    // Search contacts
    if (adminContactSearch) {
        adminContactSearch.addEventListener("input", function () {
            loadAdminContacts(adminContactSearch.value.trim());
        });
    }
}