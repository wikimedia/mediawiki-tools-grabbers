#!/usr/bin/env python

# Released into the public domain
# By Legoktm & Uncyclopedia development team

# Very simple wrapper script that calls all of the scripts at once

import blocks_table
blocks_table.insert()
import page_restrictions_table
page_restrictions_table.insert()
import protected_titles_table
protected_titles_table.insert()
import user_groups_table
user_groups_table.insert()