# Text to Image RH Plugin (Nano Banana)

Generate high-quality images from text prompts using RunningHub's Nano Banana API.

## Features

- **Text to Image Generation**: Create images from detailed text descriptions
- **Multiple Aspect Ratios**: Support for 1:1, 16:9, 9:16, 4:3, 3:4, and more
- **Resolution Options**: 1K, 2K, and 4K output resolutions
- **Text Input Port**: Connect Text Input node for dynamic prompts

## Usage

1. Drag the "Text to Image RH" node onto the canvas
2. Connect to a Start Flow node
3. Enter your prompt or connect a Text Input node
4. Select aspect ratio and resolution
5. Run the workflow

## Input Ports

| Port              | Type | Description                            |
| ----------------- | ---- | -------------------------------------- |
| Wait For          | Flow | Flow control input                     |
| Prompt (Optional) | Text | Connect Text Input for dynamic prompts |

## Output Ports

| Port         | Type  | Description         |
| ------------ | ----- | ------------------- |
| Output Image | Image | Generated image URL |

## Fields

| Field        | Type     | Description                           |
| ------------ | -------- | ------------------------------------- |
| Prompt       | Textarea | Image description (5-4000 characters) |
| Aspect Ratio | Select   | Image dimensions ratio                |
| Resolution   | Select   | Output resolution (1K/2K/4K)          |

## API

This plugin uses the RunningHub rhart-image-n-pro API:

- Endpoint: `https://www.runninghub.ai/openapi/v2/rhart-image-n-pro/text-to-image`
- Provider: `rhub`

## Requirements

- RunningHub API Key (configure in Settings → Integrations)
