# Image to Video V1 Plugin

A custom AIKAFLOW plugin for integrating RunningHub AI video generation workflows.

## Installation

1. Download this plugin as a ZIP file
2. Go to AIKAFLOW Editor → Settings → Plugins
3. Click "Upload Plugin" and select the ZIP file
4. Enable the plugin

## Configuration

After installation, you'll need to configure:

1. **Webapp ID**: Your RunningHub workflow/app ID
2. **API Key**: Your RunningHub API key

You can find these in your RunningHub dashboard.

## Usage

1. Drag the "RunningHub AI App" node from the sidebar onto the canvas
2. Connect an image input to the node
3. Configure the settings:
   - Enter your Webapp ID and API Key
   - Select the video mode (portrait/landscape)
   - Write a motion prompt describing the video
   - Set the duration (10-15 seconds)
4. Run the workflow

## Node Fields

| Field | Description |
|-------|-------------|
| Webapp ID | Your RunningHub workflow ID |
| API Key | Your RunningHub API key |
| Video Mode | Portrait, Landscape, or HD versions |
| Motion Prompt | Description of the video motion and scene |
| Duration | Video length in seconds (10-15) |

## Support

For issues or questions, please contact support@aikaflow.com

## License

MIT License
