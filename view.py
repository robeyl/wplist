import streamlit as st
import pandas as pd
import json

# Set layout to wide
st.set_page_config(layout="wide")

def load_data():
    data = []
    with open('list', 'r') as f:
        for i, line in enumerate(f, 1):
            line = line.strip()  # Remove leading/trailing whitespace
            if line:  # Skip blank lines
                try:
                    data.append(json.loads(line))
                except json.JSONDecodeError:
                    print(f'Error parsing JSON on line {i}. Content: {line}')
    return pd.DataFrame(data)

df = load_data()

# Filter by rank value range
rank_range = st.sidebar.slider('Rank range', min_value=min(df['ranked']), max_value=max(df['ranked']), value=[min(df['ranked']), max(df['ranked'])])
df = df[(df['ranked'] >= rank_range[0]) & (df['ranked'] <= rank_range[1])]

# Search for keywords
keyword = st.sidebar.text_input('Keyword', '')
df = df[df['title'].str.contains(keyword, case=False) | df['description'].str.contains(keyword, case=False)]

# Dropdown filter for version
# Sort the versions
unique_versions = df['version'].dropna().unique().tolist()
numeric_versions = sorted([v for v in unique_versions if isinstance(v, (int, float))])
alpha_versions = sorted([v for v in unique_versions if isinstance(v, str)], reverse=True)

# Combine the sorted version lists and prepend 'ALL'
sorted_versions = ['ALL'] + alpha_versions + numeric_versions

version = st.sidebar.selectbox('Version', options=sorted_versions, index=0)

# Filter by version if 'ALL' is not selected
if version != 'ALL':
    df = df[df['version'] == version]

# Add some space before displaying the data
st.markdown("---")  # Adds a horizontal line. Add more of these for more space

# Display the data
st.write(df)

# Add a note before the bottom divider
st.markdown("click and drag the bottom right corner of the table to make it taller")

# Add some space after displaying the data
st.markdown("---")  # Adds a horizontal line. Add more of these for more space
