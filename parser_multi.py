#!/usr/bin/python3
from docx import Document
from docx.table import Table
from docx.text.paragraph import Paragraph
import sys
from pdfminer.high_level import extract_text
from pptx import Presentation
from pptx.shapes.group import GroupShape
from pptx.enum.shapes import MSO_SHAPE_TYPE
import os

'''
This function will return text from a  file. Currently supports .txt, .md, .json, .xml, .docx, 
.pptx, and .pdf files.

Input:  filepath (string) - the path to the file
Output: text (string) - the contents of the file
'''
def parse_doc(file, filename):

    # Check if file exists
    if not os.path.exists(file):
        raise ValueError('File does not exist')

    # Check if file is not empty
    if os.path.getsize(file) == 0:
        raise ValueError('File is empty')

    if filename.endswith('.txt') or filename.endswith('.md') or filename.endswith('.json') or filename.endswith('.xml'):
        return parse_txt(file, filename)
    elif filename.endswith('.docx'):
        return parse_docx(file, filename)
    elif filename.endswith('.pptx'):
        return parse_pptx(file, filename)
    elif filename.endswith('.pdf'):
        return parse_pdf(file, filename)
    else:
        raise ValueError('File type not supported')


'''
This function will return text from a pdf file. It does not ready any images. And it does not 
read tables intelligently. It will simply read the text in the order it appears in the pdf.

Input:  filepath (string) - the path to the file
Output: text (string) - the contents of the file
'''
def parse_pdf(file, filename):   


    # Check if file is a pdf
    if not filename.endswith('.pdf'):
        raise ValueError('File type not supported')

    output = extract_text(file)    
    return output
    if output == "":
        return "The file returned no content"
    else: 
        return output

'''
This function will return text from a docx file. It does not ready any images or headers/footers.

Input:  filepath (string) - the path to the file
Output: text (string) - the contents of the file
'''
def parse_docx(file, filename):

    # Check if file is a docx
    if not filename.endswith('.docx'):
        raise ValueError('File type not supported')

    # loads the document
    document = Document(file)

    # We will build a string of the text in the document
    text = ''

    # The docx package breaks the document into different parts. Here we iterate over the paragrphas
    # and tables in the document and add them to the string. We could revisit this and how we add 
    # whitespace, etc.
    for item in document.iter_inner_content():
        if isinstance(item, Paragraph):            
            text +=  item.text +'\n'
        elif isinstance(item, Table):
            text += 'Table'
            for row in item.rows:
                for cell in row.cells:
                    text += cell.text + '\t'
                text+='\n'  

    # Potential TODO - read headers/footers
    
    return text

'''
Helper function for parse_pptx. This function will recursively check for text in a set of shapes.
'''
def check_recursively_for_text(this_set_of_shapes, text_run):
    for shape in this_set_of_shapes:

        # If this is a group, we have to call it recursively to get down to text/tables
        if shape.shape_type == MSO_SHAPE_TYPE.GROUP:
            check_recursively_for_text(shape.shapes, text_run)
        else:
            if shape.has_text_frame:               
                for paragraph in shape.text_frame.paragraphs:
                    for run in paragraph.runs:
                        text_run.append(run.text)
            elif shape.has_table:
                for row in shape.table.rows:
                    row_text = ''
                    for cell in row.cells:
                        row_text += cell.text + '\t'
                    text_run.append(row_text)
    return text_run

'''
This function will return text from a pptx file
'''
def parse_pptx(file, filename):

    # Check if file is a pptx
    if not filename.endswith('.pptx'):
        raise ValueError('File type not supported')

    # loads the presentation
    presentation = Presentation(file)

    # We will build a string of the text in the presentation by iterating over slides
    # and finding all text frames, tables, and groups. This skips images and other objects.
    text = []
    for slide in presentation.slides:
        text = check_recursively_for_text(slide.shapes, text)
    
    return '\n'.join(text)


'''
This function will return text from an ASCII file. Currently this accepts .txt, .md, .json, and .xml files.

Input:  filepath (string) - the path to the file
Output: contents (string) - the contents of the file
'''
def parse_txt(file, filename):

    # Check if file is a txt, md, json, or xml
    if not filename.endswith('.txt') and not filename.endswith('.md') and not filename.endswith('.json') and not filename.endswith('.xml'):
        raise ValueError('File type not supported')

    # Simply read characters of the file
    with open(file, 'r') as f:
        contents = f.read()
    return contents

if __name__ == '__main__':
    text = parse_doc(sys.argv[1], sys.argv[2])
    print(text)
    

