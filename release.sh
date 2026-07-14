#!/bin/bash

# Stop execution if any command fails
set -e

# Run the version bump command
echo "Bumping application version..."
php artisan version:bump

# Extract the new bumped version from .env.example
# Remove carriage returns if any, to prevent issues with git flow
VERSION=$(grep "^APP_VERSION=" .env.example | cut -d '=' -f 2 | tr -d '\r')

if [ -z "$VERSION" ]; then
    echo "Error: Could not extract APP_VERSION from .env.example"
    exit 1
fi

# Stash the changes made by version:bump
echo "Stashing unstaged changes..."
git stash

# Start the git flow release
echo "Starting git flow release for version: $VERSION"
git flow release start "v$VERSION"

# Pop the stashed changes back into the release branch
echo "Applying stashed changes..."
git stash pop 0

# Commit the version bump
echo "Committing the version bump..."
git add .env.example
git commit -m "chore(release): version bumped to $VERSION"

# Publishing the release
echo "Publishing the release..."
git flow release publish

echo "Done!"
