USE [TradewellDatabase]
GO

/****** Object:  Table [dbo].[JobApplications]    Script Date: 4/10/2026 2:59:06 PM ******/
SET ANSI_NULLS ON
GO

SET QUOTED_IDENTIFIER ON
GO

CREATE TABLE [dbo].[JobApplications](
	[ApplicationID] [int] IDENTITY(1,1) NOT NULL,
	[Fullname] [nvarchar](100) NOT NULL,
	[Email] [nvarchar](100) NOT NULL,
	[Phone] [nvarchar](50) NOT NULL,
	[Position] [nvarchar](100) NOT NULL,
	[DateApplied] [datetime] NULL,
	[Status] [tinyint] NOT NULL,
	[DepartmentID] [int] NULL,
	[TnCAccepted] [bit] NOT NULL,
	[TnCAcceptedAt] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
	[ApplicationID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO

ALTER TABLE [dbo].[JobApplications] ADD  DEFAULT (getdate()) FOR [DateApplied]
GO

ALTER TABLE [dbo].[JobApplications] ADD  CONSTRAINT [DF_JobApplications_Status]  DEFAULT ((0)) FOR [Status]
GO

ALTER TABLE [dbo].[JobApplications] ADD  DEFAULT ((0)) FOR [TnCAccepted]
GO

ALTER TABLE [dbo].[JobApplications]  WITH CHECK ADD  CONSTRAINT [FK_JobApplications_Departments] FOREIGN KEY([DepartmentID])
REFERENCES [dbo].[Departments] ([DepartmentID])
GO

ALTER TABLE [dbo].[JobApplications] CHECK CONSTRAINT [FK_JobApplications_Departments]
GO

ALTER TABLE [dbo].[JobApplications]  WITH CHECK ADD  CONSTRAINT [CHK_JobApplications_Status] CHECK  (([Status]=(7) OR [Status]=(6) OR [Status]=(5) OR [Status]=(4) OR [Status]=(3) OR [Status]=(2) OR [Status]=(1) OR [Status]=(0)))
GO

ALTER TABLE [dbo].[JobApplications] CHECK CONSTRAINT [CHK_JobApplications_Status]
GO


