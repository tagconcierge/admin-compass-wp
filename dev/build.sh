#!/bin/bash

# Script to build Admin Compass plugin ZIP for installation

# Clear and create directories
rm -rf ./dist
mkdir -p ./dist/admin-compass

# Copy plugin files
cp admin-compass.php ./dist/admin-compass/
cp admin-compass.js ./dist/admin-compass/
cp admin-compass.css ./dist/admin-compass/
cp admin-compass-demo-setup.php ./dist/admin-compass/
cp admin-compass-load-test.php ./dist/admin-compass/
cp readme.txt ./dist/admin-compass/

# Create ZIP file
cd ./dist
zip -r admin-compass.zip admin-compass/
cd ..

echo "✅ Plugin ZIP created: ./dist/admin-compass.zip"
echo "📦 Ready for WordPress installation!"

# Optional: Clean up folder, keep only ZIP
# rm -rf ./dist/admin-compass/