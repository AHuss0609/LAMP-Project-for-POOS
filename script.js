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

                window.location.href = "contacts.html";
            } else {
                loginError.textContent = "Invalid username or password.";
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