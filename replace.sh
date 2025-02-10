#!/bin/bash
# Replace 'ispconfig' with 'opanel' in all files in the repository

# Find all files in the repository
files=$(grep -rl 'ispconfig' .)

# Loop through each file and replace 'ispconfig' with 'opanel'
for file in $files; do
    sed -i 's/ispconfig/opanel/g' "$file"
done

echo "Replacement complete."
