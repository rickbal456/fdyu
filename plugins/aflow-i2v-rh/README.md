# Image to Video RH Plugin

A custom AIKAFLOW plugin for generating videos from images using RunningHub's RH Art Video S API.

## Features

- Generate 10-15 second videos from static images
- Support for landscape (16:9) and portrait (9:16) aspect ratios
- Detailed motion prompts up to 4000 characters
- Uses RunningHub V2 API infrastructure

## Installation

1. Download this plugin as a ZIP file
2. Go to AIKAFLOW Editor → Settings → Plugins
3. Click "Upload Plugin" and select the ZIP file
4. Enable the plugin

## Configuration

After installation, configure your RunningHub API key:

1. Go to Settings → Integrations
2. Enter your RunningHub API key
3. The plugin will automatically use this key

Alternatively, you can enter the API key directly in the node field.

## Usage

1. Drag the "Image to Video RH" node from the sidebar onto the canvas
2. Connect an image input to the node
3. Configure the settings:
   - Select the aspect ratio (16:9 or 9:16)
   - Write a detailed motion prompt describing the animation
   - Set the duration (10 or 15 seconds)
4. Run the workflow

## Node Fields

| Field | Description |
|-------|-------------|
| API Key | Your RunningHub API key (optional if configured in Settings) |
| Aspect Ratio | Video orientation: 16:9 (Landscape) or 9:16 (Portrait) |
| Motion Prompt | Detailed description of the animation and motion (5-4000 characters) |
| Duration | Video length in seconds (10 or 15) |

## API Reference

This plugin uses the RunningHub V2 rhart-video-s API:
- Endpoint: `https://www.runninghub.ai/openapi/v2/rhart-video-s/image-to-video`
- Query Endpoint: `https://www.runninghub.ai/openapi/v2/query`

## Support

For issues or questions, please contact support@aikaflow.com

## License

MIT License
