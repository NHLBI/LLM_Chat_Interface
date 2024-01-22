#!/usr/bin/python3
from pdfminer.high_level import extract_text
import sys
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def main(filename):
    try:
        text = extract_text(filename)
        print(text.encode('utf-8'))

    except Exception as e:
        logger.error(f"An error occurred: {e}")
        sys.exit(1)

if __name__ == '__main__':
    if len(sys.argv) != 2:
        logger.error("Usage: python extract_text.py <PDF file>")
        sys.exit(1)

    main(sys.argv[1])

