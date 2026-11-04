INSERT INTO users (
    name,
    email,
    password_hash,
    role,
    status,
    created_at
) VALUES (
    :name,
    :email,
    :password_hash,
    'system_admin',
    'active',
    :created_at
);
