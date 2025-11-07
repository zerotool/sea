Sea Battle Prototype
This project contains a browser‑based prototype for a sea battle game. It uses HTML5 canvas to draw a small ocean made of hexagonal sectors and lets you control a battleship on the screen. The game is designed to run entirely client‑side in a modern web browser.
Features
•	Hexagonal sea grid – The sea consists of two rows and three columns of flat‑topped hexagonal sectors. Each sector is named using a row/column pattern (e.g. A‑1, A‑2, B‑3).
•	Full‑screen canvas – The game fills the entire browser window. Resizing the window automatically adjusts the canvas to stay full screen.
•	Free‑movement ship – A battleship, represented by a circle and ship emoji, can be clicked and moved to any X/Y coordinate within the canvas. It is no longer restricted to sector centers.
•	Sector detection – Whenever you click to move the ship, the code computes which hex sector the ship’s position falls into based on its coordinates. The current sector’s name is displayed in the info panel at the top left. If the ship is outside the predefined hex grid, the panel shows “Outside”.
•	Simple UI – An information panel shows the current sector. The hex tiles are drawn with a soft blue fill and labelled.
Running the prototype locally
1.	Ensure you have a local web server available. You can use Node.js’s serve package or Python’s built‑in HTTP server.
2.	Navigate to the sea directory in a terminal:
cd ~/Downloads/sea
1.	Start a server. For example, using npx serve:
npx serve .
Or with Python:
python3 -m http.server
1.	Open your browser and go to http://localhost:YOUR_PORT/index.html (the port is shown by your server, often 3000 for serve or 8000 for Python). The game should load full‑screen.
2.	Click anywhere on the canvas to move the battleship. Watch how the Current Sector label updates based on your ship’s location.
Modifying the grid
The grid size and labels are defined in index.html in the HEX_SIZE, NUM_COLS, NUM_ROWS and LABELS variables. Changing these values allows you to make a larger map or adjust the size of each hex.
Enjoy experimenting with the prototype, and feel free to expand it with more ships, obstacles, or gameplay mechanics!
 

